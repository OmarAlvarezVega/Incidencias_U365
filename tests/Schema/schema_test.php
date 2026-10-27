<?php
// Prueba reproducible del esquema. Requiere una base de datos VACÍA con las
// migraciones 0001 y 0002 aplicadas. Nunca la ejecutes contra producción:
// crea 800 incidencias de prueba.
//
// Variables de entorno: TEST_DB_DSN, TEST_DB_USER, TEST_DB_PASS
// Ejemplo: TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=u365t;charset=utf8mb4' php tests/Schema/schema_test.php
declare(strict_types=1);
function db(): PDO {
  $dsn = getenv('TEST_DB_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=u365t;charset=utf8mb4';
  return new PDO($dsn, getenv('TEST_DB_USER') ?: 't', getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : 't', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
$mode = $argv[1] ?? 'main';
if ($mode === 'worker') {               // crea N incidencias numeradas en transacción
  $pdo = db(); $n = (int)$argv[2];
  $prio = (int)$pdo->query("SELECT id FROM ticket_priorities WHERE code='normal'")->fetchColumn();
  $st = (int)$pdo->query("SELECT id FROM ticket_statuses WHERE code='new'")->fetchColumn();
  for ($i=0;$i<$n;$i++) {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO ticket_sequences (year,last_value) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value+1)")->execute([2026]);
    $seq = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();
    $num = sprintf('INC-%d-%06d', 2026, $seq);
    $pdo->prepare("INSERT INTO tickets (ticket_number,seq_year,seq_value,subject,identification_status,priority_id,status_id,channel_code,last_activity_at,created_at,updated_at) VALUES (?,?,?,?, 'pending', ?,?, 'manual', UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$num,2026,$seq,"Prueba $seq",$prio,$st]);
    $pdo->commit();
  }
  exit(0);
}
$ok = 0; $fail = 0;
function check(string $name, bool $cond) { global $ok,$fail; echo ($cond?'PASA ':'FALLA')." $name\n"; $cond?$ok++:$fail++; }
function rejects(string $name, callable $f) { try { $f(); check($name, false); } catch (PDOException $e) { check($name.' ['.$e->errorInfo[1].']', true); } }
$pdo = db();
// 1. numeración concurrente: 8 procesos x 100
$procs=[]; for($p=0;$p<8;$p++){ $procs[] = proc_open([PHP_BINARY, __FILE__, 'worker', '100'], [], $pipes); }
foreach($procs as $pr) proc_close($pr);
$r = $pdo->query("SELECT COUNT(*) c, COUNT(DISTINCT ticket_number) d, MIN(seq_value) mn, MAX(seq_value) mx FROM tickets")->fetch();
check("Numeración concurrente: 800 incidencias, 800 números únicos, 1..800 sin huecos (c={$r['c']} d={$r['d']} min={$r['mn']} max={$r['mx']})", $r['c']==800 && $r['d']==800 && $r['mn']==1 && $r['mx']==800);
check("Formato INC-2026-000001", $pdo->query("SELECT ticket_number FROM tickets WHERE seq_value=1")->fetchColumn()==='INC-2026-000001');
// usuario de prueba
$role = (int)$pdo->query("SELECT id FROM roles WHERE code='technician'")->fetchColumn();
$pdo->prepare("INSERT INTO users (email,email_normalized,password_hash,role_id,created_at,updated_at) VALUES ('a@x.es','a@x.es',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([password_hash('x',PASSWORD_DEFAULT),$role]);
$uid = (int)$pdo->lastInsertId();
rejects("Usuario de portal sin contacto vinculado es rechazado", fn()=>$pdo->prepare("INSERT INTO users (user_type,email,email_normalized,password_hash,role_id,created_at,updated_at) VALUES ('client','c@x.es','c@x.es','h',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$role]));
rejects("Incidencia identificada sin cliente es rechazada", fn()=>$pdo->exec("INSERT INTO tickets (ticket_number,seq_year,seq_value,subject,identification_status,priority_id,status_id,channel_code,last_activity_at,created_at,updated_at) VALUES ('INC-2026-999999',2026,999999,'x','identified',1,1,'manual',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
// 2. temporizador único
$tk = (int)$pdo->query("SELECT id FROM tickets LIMIT 1")->fetchColumn();
$ins = $pdo->prepare("INSERT INTO ticket_time_entries (ticket_id,user_id,source,started_at,work_date,created_at,updated_at) VALUES (?,?, 'timer', UTC_TIMESTAMP(), CURDATE(), UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$ins->execute([$tk,$uid]); $t1 = (int)$pdo->lastInsertId();
rejects("Segundo temporizador activo del mismo usuario es rechazado", fn()=>$ins->execute([$tk+1,$uid]));
$pdo->exec("UPDATE ticket_time_entries SET ended_at = DATE_ADD(started_at, INTERVAL 30 MINUTE), minutes=30 WHERE id=$t1");
try { $ins->execute([$tk+1,$uid]); check("Tras parar el temporizador se puede iniciar otro", true);} catch(PDOException $e){ check("Tras parar el temporizador se puede iniciar otro", false);}
rejects("Registro manual sin minutos es rechazado", fn()=>$pdo->exec("INSERT INTO ticket_time_entries (ticket_id,user_id,source,work_date,created_at,updated_at) VALUES ($tk,$uid,'manual',CURDATE(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
rejects("Temporizador que termina antes de empezar es rechazado", fn()=>$pdo->exec("INSERT INTO ticket_time_entries (ticket_id,user_id,source,started_at,ended_at,work_date,created_at,updated_at) VALUES ($tk,$uid,'timer','2026-01-01 10:00','2026-01-01 09:00',CURDATE(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
// 3. nota interna nunca pública
rejects("Nota interna marcada como pública es rechazada", fn()=>$pdo->exec("INSERT INTO ticket_activities (ticket_id,user_id,kind,visibility,body,created_at,updated_at) VALUES ($tk,$uid,'internal_note','public','x',UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
// 4. categorías duplicadas en raíz
rejects("Categoría raíz duplicada es rechazada", fn()=>$pdo->exec("INSERT INTO ticket_categories (parent_id,name) VALUES (NULL,'Redes')"));
$redes = (int)$pdo->query("SELECT id FROM ticket_categories WHERE name='Redes'")->fetchColumn();
$pdo->exec("INSERT INTO ticket_categories (parent_id,name) VALUES ($redes,'Wifi')");
rejects("Subcategoría duplicada bajo el mismo padre es rechazada", fn()=>$pdo->exec("INSERT INTO ticket_categories (parent_id,name) VALUES ($redes,'Wifi')"));
// 5. duplicados de integraciones
$pdo->exec("INSERT INTO whatsapp_numbers (phone_number_id,waba_id,display_phone,created_at,updated_at) VALUES ('1','2','+34',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO whatsapp_conversations (number_id,wa_id,created_at,updated_at) VALUES (1,'34600000000',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$wm = "INSERT INTO whatsapp_messages (conversation_id,wamid,direction,msg_type,status,created_at) VALUES (1,'wamid.ABC','inbound','text','received',UTC_TIMESTAMP())";
$pdo->exec($wm); rejects("Mensaje de WhatsApp repetido (mismo wamid) es rechazado", fn()=>$pdo->exec($wm));
$ev = "INSERT INTO whatsapp_webhook_events (received_at,signature_valid,payload_sha256,payload) VALUES (UTC_TIMESTAMP(3),1,SHA2('p',256),'p')";
$pdo->exec($ev); rejects("Webhook repetido (mismo cuerpo) es rechazado", fn()=>$pdo->exec($ev));
$pdo->exec("INSERT INTO email_accounts (name,address,imap_host,imap_username,smtp_host,smtp_username,created_at,updated_at) VALUES ('s','soporte@x.es','h','u','h','u',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$em = "INSERT INTO email_messages (account_id,direction,message_id,message_id_hash,imap_uidvalidity,imap_uid,from_address,received_at,created_at) VALUES (1,'inbound','<a@b>',SHA2('<a@b>',256),%d,%d,'c@d.es',UTC_TIMESTAMP(),UTC_TIMESTAMP())";
$pdo->exec(sprintf($em,1,1)); rejects("Correo repetido (mismo Message-ID) es rechazado", fn()=>$pdo->exec(sprintf($em,1,2)));
// 6. identidad de contacto única
$pdo->exec("INSERT INTO clients (code,client_type,legal_name,registered_on,created_at,updated_at) VALUES ('CLI-1','company','ACME SL',CURDATE(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO client_contacts (client_id,first_name,created_at,updated_at) VALUES (1,'Ana',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(1,'Luis',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO contact_identities (contact_id,kind,value,value_normalized,created_at,updated_at) VALUES (1,'email','Ana@ACME.es','ana@acme.es',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
rejects("El mismo correo no puede pertenecer a dos contactos", fn()=>$pdo->exec("INSERT INTO contact_identities (contact_id,kind,value,value_normalized,created_at,updated_at) VALUES (2,'email','ana@acme.es','ana@acme.es',UTC_TIMESTAMP(),UTC_TIMESTAMP())"));
// 7. texto español
$pdo->exec("UPDATE tickets SET subject='Impresora de administración: año, señal, ¿qué?' WHERE id=$tk");
check("utf8mb4 conserva acentos y eñes", $pdo->query("SELECT subject FROM tickets WHERE id=$tk")->fetchColumn()==='Impresora de administración: año, señal, ¿qué?');
check("Búsqueda FULLTEXT encuentra 'impresora'", (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE MATCH(subject,description) AGAINST('impresora' IN BOOLEAN MODE)")->fetchColumn()===1);
echo "\nResultado: $ok pasan, $fail fallan\n"; exit($fail?1:0);
