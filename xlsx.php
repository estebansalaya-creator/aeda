<?php
/**
 * Generador de planillas .xlsx, sin librerías.
 *
 * Un .xlsx es un ZIP con unos pocos XML adentro. Para lo que hace falta acá
 * —una hoja, encabezado en negrita, filtros y anchos de columna— escribirlo
 * a mano son doscientas líneas y evita meter PhpSpreadsheet con composer en
 * Railway, que es justo lo que este proyecto viene evitando en mail.php.
 *
 * Tampoco usa la extensión zip: el contenedor de PHP no siempre la trae.
 * El ZIP se arma con gzdeflate(), que es parte de zlib y está siempre.
 *
 * Uso:
 *   $bytes = xlsxArmar('Inscriptos',
 *                      ['Documento', 'Titular'],
 *                      [[30111222, 'Pérez, Juan'], ...]);
 *
 * Los valores numéricos se escriben como números (Excel los suma); todo lo
 * demás va como texto, incluidas las fechas ya formateadas. Es a propósito:
 * una fecha como texto se lee igual y no depende de la configuración
 * regional de quien abra el archivo.
 */

declare(strict_types=1);

/** Escapa lo que no puede ir crudo dentro de un XML. */
function xlsxTexto(string $v): string
{
    // Los caracteres de control rompen el archivo y Excel se niega a abrirlo
    // sin decir por qué. Se limpian antes de escapar.
    // Si el texto no fuera UTF-8 válido, preg_replace devuelve null: en ese
    // caso se deja el original y ENT_SUBSTITUTE se encarga de los bytes raros,
    // que es mejor que perder el contenido de la celda.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? $v;
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Largo en caracteres, no en bytes.
 *
 * mbstring no siempre está compilada —mail.php ya se cuida de eso— y acá
 * sólo se usa para calcular anchos de columna: si falta, strlen() cuenta
 * de más en las palabras con acento y la columna queda un poco más ancha.
 * Es un defecto que no se nota; un error fatal sí.
 */
function xlsxLargo(string $v): int
{
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}

/** Nombre de columna de Excel a partir del índice: 0 -> A, 26 -> AA. */
function xlsxColumna(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

/** Una celda, numérica o de texto en línea. */
function xlsxCelda(string $ref, $valor, int $estilo): string
{
    $s = $estilo ? " s=\"$estilo\"" : '';

    // is_numeric() acepta "0012345", y ese cero adelante se perdería. Sólo
    // se trata como número lo que ya viene tipado como tal.
    if (is_int($valor) || is_float($valor)) {
        return "<c r=\"$ref\"$s><v>$valor</v></c>";
    }
    $texto = xlsxTexto((string) $valor);
    if ($texto === '') {
        return $s ? "<c r=\"$ref\"$s/>" : '';
    }
    return "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">$texto</t></is></c>";
}

/** Ancho de cada columna, mirando el contenido con un mínimo y un máximo. */
function xlsxAnchos(array $encabezados, array $filas): array
{
    $anchos = [];
    foreach ($encabezados as $i => $e) {
        $anchos[$i] = xlsxLargo((string) $e);
    }
    foreach ($filas as $fila) {
        foreach (array_values($fila) as $i => $v) {
            $largo = xlsxLargo((string) $v);
            if ($largo > ($anchos[$i] ?? 0)) {
                $anchos[$i] = $largo;
            }
        }
    }
    foreach ($anchos as $i => $a) {
        $anchos[$i] = max(9, min(46, $a + 3));
    }
    return $anchos;
}

/** La hoja: encabezado fijo, filtros automáticos y las filas de datos. */
function xlsxHoja(array $encabezados, array $filas): string
{
    $ultima = xlsxColumna(max(0, count($encabezados) - 1));
    $total  = count($filas) + 1;

    $cols = '';
    foreach (xlsxAnchos($encabezados, $filas) as $i => $ancho) {
        $n = $i + 1;
        $cols .= "<col min=\"$n\" max=\"$n\" width=\"$ancho\" customWidth=\"1\"/>";
    }

    $xml = '<row r="1" ht="20" customHeight="1">';
    foreach ($encabezados as $i => $e) {
        $xml .= xlsxCelda(xlsxColumna($i) . '1', (string) $e, 1);
    }
    $xml .= '</row>';

    $n = 1;
    foreach ($filas as $fila) {
        $n++;
        $xml .= "<row r=\"$n\">";
        foreach (array_values($fila) as $i => $v) {
            $xml .= xlsxCelda(xlsxColumna($i) . $n, $v, 0);
        }
        $xml .= '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . "<sheetPr><outlinePr summaryBelow=\"1\"/></sheetPr>"
         . "<dimension ref=\"A1:$ultima$total\"/>"
         . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
         // La primera fila queda clavada arriba al bajar por el listado.
         . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
         . '</sheetView></sheetViews>'
         . '<sheetFormatPr defaultRowHeight="15"/>'
         . "<cols>$cols</cols>"
         . "<sheetData>$xml</sheetData>"
         . "<autoFilter ref=\"A1:$ultima$total\"/>"
         . '</worksheet>';
}

/** Dos estilos: el 0 por omisión y el 1 para el encabezado. */
function xlsxEstilos(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<fonts count="2">'
         . '<font><sz val="11"/><name val="Calibri"/></font>'
         . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
         . '</fonts>'
         . '<fills count="3">'
         . '<fill><patternFill patternType="none"/></fill>'
         . '<fill><patternFill patternType="gray125"/></fill>'
         . '<fill><patternFill patternType="solid"><fgColor rgb="FF002449"/>'
         . '<bgColor indexed="64"/></patternFill></fill>'
         . '</fills>'
         . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
         . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
         . '<cellXfs count="2">'
         . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
         . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"'
         . ' applyAlignment="1"><alignment vertical="center"/></xf>'
         . '</cellXfs>'
         . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
         . '</styleSheet>';
}

/**
 * Arma el ZIP a mano.
 *
 * Formato clásico de PKZIP: por cada archivo un encabezado local seguido de
 * los datos, y al final el directorio central que dice dónde empieza cada
 * uno. Sin ZIP64 ni descriptores: para archivos de este tamaño no hacen
 * falta y agregarlos sólo sumaría formas de equivocarse.
 */
function xlsxZip(array $archivos): string
{
    $local   = '';
    $central = '';
    $offset  = 0;

    // Fecha y hora en el formato DOS que usa el ZIP: la misma para todas las
    // entradas, que es cuando se generó la planilla.
    $t    = getdate();
    $hora = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dia  = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

    foreach ($archivos as $nombre => $contenido) {
        $crc   = crc32($contenido);
        $crudo = strlen($contenido);
        // gzdeflate() devuelve deflate puro, que es justo lo que el método 8
        // del ZIP espera (gzencode() agregaría una cabecera gzip y rompería).
        $comp  = gzdeflate($contenido, 6);
        if ($comp === false) {
            throw new RuntimeException("no se pudo comprimir $nombre");
        }
        $largo = strlen($comp);

        // 26 bytes que van iguales en el encabezado local y en el central:
        // versión, flags, método, hora, fecha, CRC, tamaños y largo del nombre.
        $cabecera = pack('vvvvv', 20, 0, 8, $hora, $dia)
                  . pack('VVV', $crc, $largo, $crudo)
                  . pack('vv', strlen($nombre), 0);

        $local .= "PK\x03\x04" . $cabecera . $nombre . $comp;

        $central .= "PK\x01\x02" . pack('v', 20) . $cabecera
                  // comentario, disco, atributos internos y externos, y la
                  // posición donde arranca el encabezado local de esta entrada.
                  . pack('vvvV', 0, 0, 0, 0) . pack('V', $offset) . $nombre;

        $offset = strlen($local);
    }

    $cantidad = count($archivos);

    return $local . $central . "PK\x05\x06"
         . pack('vvvv', 0, 0, $cantidad, $cantidad)
         . pack('VV', strlen($central), strlen($local))
         . pack('v', 0);
}

/**
 * Devuelve el contenido binario de un .xlsx de una sola hoja.
 *
 * @param string $hoja         Nombre de la solapa.
 * @param array  $encabezados  Títulos de las columnas.
 * @param array  $filas        Lista de filas; cada una, lista de valores.
 */
function xlsxArmar(string $hoja, array $encabezados, array $filas): string
{
    // Excel no acepta ciertos caracteres en el nombre de la solapa y corta
    // en 31; si se pasa, el archivo se abre "reparado" y asusta.
    $hoja = preg_replace('/[\\\\\\/\\*\\?\\[\\]:]/u', '-', $hoja) ?: 'Hoja1';
    $hoja = function_exists('mb_substr') ? mb_substr($hoja, 0, 31) : substr($hoja, 0, 31);

    return xlsxZip([
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . xlsxTexto($hoja) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        'xl/styles.xml'            => xlsxEstilos(),
        'xl/worksheets/sheet1.xml' => xlsxHoja($encabezados, $filas),
    ]);
}
