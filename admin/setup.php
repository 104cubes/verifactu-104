<?php
/* Setup page for Verifactu104 module */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

$langs->load("admin");
$langs->load("verifactu104@verifactu104");

if (! $user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');

// Directorio seguro para certificados
$upload_dir = DOL_DATA_ROOT . '/verifactu104/certs/';
dol_mkdir($upload_dir);

// Guardar configuración
if ($action == 'save') {
	// Guardar modo y auto envío
	dolibarr_set_const($db, "VERIFACTU_MODE", GETPOST("VERIFACTU_MODE", 'alpha'), 'chaine', 0, '', $conf->entity);
	$auto_send = GETPOST("VERIFACTU_AUTO_SEND", 'alpha') ? 1 : 0;
	dolibarr_set_const($db, "VERIFACTU_AUTO_SEND", $auto_send, 'int', 0, '', $conf->entity);

	// ---------------------------------------------
	// PROCESAR ZIP → cert.pem + key.pem + ca-bundle.crt
	// ---------------------------------------------
	if (!empty($_FILES['cert_zip']['tmp_name'])) {
		$tmp = $_FILES['cert_zip']['tmp_name'];
		$destzip = $upload_dir . '/certificados.zip';
		move_uploaded_file($tmp, $destzip);

		echo "<pre>📦 ZIP recibido: {$_FILES['cert_zip']['name']}</pre>";

		$zip = new ZipArchive();
		if ($zip->open($destzip) === TRUE) {
			if ($zip->numFiles > 0) {
				echo "<pre>Descomprimiendo archivos...</pre>";
				$zip->extractTo($upload_dir);
				$zip->close();

				// Mostrar resultado
				$expected = ['cert.pem', 'key.pem', 'ca-bundle.crt'];
				foreach ($expected as $f) {
					if (file_exists($upload_dir . $f)) {
						echo "<pre>✅ Encontrado $f</pre>";
					} else {
						echo "<pre>⚠️ Falta $f en el ZIP</pre>";
					}
				}
			} else {
				// ZIP vacío → borrar certificados existentes
				echo "<pre>⚠️ ZIP vacío. Eliminando certificados existentes...</pre>";
				array_map('unlink', glob($upload_dir . "*.{pem,crt,key}", GLOB_BRACE));
			}
			unlink($destzip);
			echo "<pre>🗑️ ZIP eliminado</pre>";
		} else {
			echo "<pre>❌ Error al abrir el ZIP</pre>";
		}
	}

	// ---------------------------------------------
	// PROCESAR P12 → cert.pem + key.pem + ca-bundle.crt
	// ---------------------------------------------
	if (!empty($_FILES['cert_p12']['tmp_name'])) {
		$p12_tmp = $_FILES['cert_p12']['tmp_name'];
		$p12_pass = GETPOST("cert_p12_pass", "alphanohtml");

		echo "<pre>📌 Archivo P12 recibido: {$_FILES['cert_p12']['name']}</pre>";

		// No guardar contraseña, solo usarla en memoria
		if (empty($p12_pass)) {
			echo "<pre>❌ Debes introducir la contraseña del archivo P12.</pre>";
		} else {
			$p12_content = file_get_contents($p12_tmp);
			$certs = [];

			if (!openssl_pkcs12_read($p12_content, $certs, $p12_pass)) {
				echo "<pre>❌ No se pudo descifrar el archivo P12. Contraseña incorrecta o archivo inválido.</pre>";
			} else {
				// Guardar CERT
				if (!empty($certs['cert'])) {
					file_put_contents($upload_dir . "cert.pem", $certs['cert']);
					echo "<pre>✔️ cert.pem generado</pre>";
				}

				// Guardar KEY
				if (!empty($certs['pkey'])) {
					file_put_contents($upload_dir . "key.pem", $certs['pkey']);
					echo "<pre>✔️ key.pem generado</pre>";
				}

				// Guardar CA si existe
				if (!empty($certs['extracerts'])) {
					// Si hay varias, las concatenamos
					file_put_contents($upload_dir . "ca-bundle.crt", implode("\n", $certs['extracerts']));
					echo "<pre>✔️ ca-bundle.crt generado</pre>";
				} else {
					echo "<pre>⚠️ No se encontraron certificados CA en el P12.</pre>";
				}
			}
		}
	}
}

// Recuperar valores actuales
$mode      = getDolGlobalString('VERIFACTU_MODE');
$auto_send = getDolGlobalInt('VERIFACTU_AUTO_SEND');

// -------------------- VIEW --------------------
llxHeader('', 'Configuración VeriFactu 104', '', '', 0, 0, '', '', 0, 0, 'none');
print load_fiche_titre('Configuración VeriFactu 104', '', 'fa-file');
print '<div class="info" style="background:#fff3cd;border:1px solid #ffeeba;padding:12px;margin-bottom:20px;">
<b>Aviso importante:</b><br>
Este módulo genera todos los elementos obligatorios del RSIF (hash, XML, QR y trazabilidad), pero <b>no incluye el método de envío automático a Hacienda</b>.<br><br>
Si activas la opción de “Envío automático”, debes haber implementado previamente tu propio método de envío VeriFactu, y siempre probar primero en el entorno de <b>pruebas</b>.<br><br>
No actives el modo “Producción” sin haber desarrollado y validado ese método. De lo contrario, aparecerán errores al intentar enviar las facturas.
</div>';

// Inicio formulario
print '<form method="POST" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

// --- Parámetros de configuración ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th colspan="2">Parámetros de configuración</th></tr>';

print '<tr><td width="40%">Modo de envío</td><td>';
print '<select name="VERIFACTU_MODE">';
print '<option value="test"' . ($mode == 'test' ? ' selected' : '') . '>Entorno de pruebas (prewww2)</option>';
print '<option value="prod"' . ($mode == 'prod' ? ' selected' : '') . '>Producción (www2)</option>';
print '</select>';
print '</td></tr>';

print '<tr><td>Envío automático a Hacienda</td><td>';
print '<input type="checkbox" name="VERIFACTU_AUTO_SEND" value="1"' . ($auto_send ? ' checked' : '') . '> Activar';
print '</td></tr>';
print '</table><br>';

// --- Subida ZIP / P12 ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th>Certificados</th><th>Acción</th></tr>';

// Método ZIP
print '<tr>';
print '<td>';
print 'Sube un archivo ZIP que contenga <strong>cert.pem</strong>, <strong>key.pem</strong> y <strong>ca-bundle.crt</strong>.<br>';
print 'Si el ZIP está vacío, se eliminarán los certificados existentes.';
print '</td>';
print '<td><input type="file" name="cert_zip" accept=".zip"></td>';
print '</tr>';

// Método P12
print '<tr>';
print '<td>Sube un archivo <strong>.p12</strong> y se convertirá automáticamente a los PEM necesarios.</td>';
print '<td><input type="file" name="cert_p12" accept=".p12"></td>';
print '</tr>';

print '<tr>';
print '<td>Contraseña del archivo P12</td>';
print '<td><input type="password" name="cert_p12_pass" autocomplete="off"></td>';
print '</tr>';

print '</table><br>';

// --- Mostrar estado actual ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th>Archivo</th><th>Estado actual</th></tr>';

$expected = ['cert.pem', 'key.pem', 'ca-bundle.crt'];
foreach ($expected as $f) {
	$filepath = $upload_dir . $f;
	print '<tr><td>' . $f . '</td><td>';
	if (file_exists($filepath)) {
		print '<span style="color:green">✔️ ' . dol_escape_htmltag($filepath) . '</span>';
	} else {
		print '<span style="color:#999">— No encontrado —</span>';
	}
	print '</td></tr>';
}
print '</table>';

print '<br><input type="submit" class="button" value="Guardar">';
print '</form>';

llxFooter();
$db->close();
