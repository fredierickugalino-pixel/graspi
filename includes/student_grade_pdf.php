<?php
function studentGradePdfEscape($value)
{
    $text = function_exists("iconv")
        ? iconv("UTF-8", "Windows-1252//TRANSLIT//IGNORE", (string) $value)
        : preg_replace('/[^\x20-\x7E]/', "?", (string) $value);
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text ?: "");
}

function studentGradePdfText($text, $x, $y, $font = "F1", $size = 9, $color = "0.16 0.22 0.18")
{
    return "{$color} rg BT /{$font} {$size} Tf {$x} {$y} Td (" . studentGradePdfEscape($text) . ") Tj ET\n";
}

function studentGradePdfTruncate($text, $maxCharacters)
{
    $text = (string) $text;
    if (function_exists("mb_strlen") && mb_strlen($text, "UTF-8") > $maxCharacters) {
        return mb_substr($text, 0, max(1, $maxCharacters - 3), "UTF-8") . "...";
    }
    return strlen($text) > $maxCharacters ? substr($text, 0, max(1, $maxCharacters - 3)) . "..." : $text;
}

function studentGradePdfPngImages($pngPath)
{
    $png = @file_get_contents($pngPath);
    if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

    $offset = 8;
    $idat = "";
    $header = null;
    $pngLength = strlen($png);
    while ($offset + 12 <= $pngLength) {
        $chunkLength = unpack("N", substr($png, $offset, 4))[1];
        $chunkType = substr($png, $offset + 4, 4);
        $chunkData = substr($png, $offset + 8, $chunkLength);
        if ($chunkType === "IHDR") $header = unpack("Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace", $chunkData);
        if ($chunkType === "IDAT") $idat .= $chunkData;
        $offset += 12 + $chunkLength;
        if ($chunkType === "IEND") break;
    }
    if (!$header || $header["bitDepth"] !== 8 || !in_array($header["colorType"], [2, 6], true) || $header["interlace"] !== 0) return null;

    $channels = $header["colorType"] === 6 ? 4 : 3;
    $rowBytes = $header["width"] * $channels;
    $decoded = @gzuncompress($idat);
    if ($decoded === false || strlen($decoded) < ($rowBytes + 1) * $header["height"]) return null;

    $rgbRows = "";
    $alphaRows = "";
    $previous = array_fill(0, $rowBytes, 0);
    for ($y = 0; $y < $header["height"]; $y++) {
        $rowOffset = $y * ($rowBytes + 1);
        $filter = ord($decoded[$rowOffset]);
        $row = array_fill(0, $rowBytes, 0);
        for ($i = 0; $i < $rowBytes; $i++) {
            $value = ord($decoded[$rowOffset + 1 + $i]);
            $left = $i >= $channels ? $row[$i - $channels] : 0;
            $above = $previous[$i];
            $upperLeft = $i >= $channels ? $previous[$i - $channels] : 0;
            if ($filter === 1) $value += $left;
            elseif ($filter === 2) $value += $above;
            elseif ($filter === 3) $value += (int) floor(($left + $above) / 2);
            elseif ($filter === 4) {
                $estimate = $left + $above - $upperLeft;
                $leftDistance = abs($estimate - $left);
                $aboveDistance = abs($estimate - $above);
                $upperLeftDistance = abs($estimate - $upperLeft);
                $predictor = $leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance
                    ? $left
                    : ($aboveDistance <= $upperLeftDistance ? $above : $upperLeft);
                $value += $predictor;
            } elseif ($filter !== 0) return null;
            $row[$i] = $value & 255;
        }
        $rgbRows .= "\0";
        $alphaRows .= "\0";
        for ($x = 0; $x < $header["width"]; $x++) {
            $pixelOffset = $x * $channels;
            $rgbRows .= chr($row[$pixelOffset]) . chr($row[$pixelOffset + 1]) . chr($row[$pixelOffset + 2]);
            if ($channels === 4) $alphaRows .= chr($row[$pixelOffset + 3]);
        }
        $previous = $row;
    }

    return [
        "width" => $header["width"],
        "height" => $header["height"],
        "rgb" => gzcompress($rgbRows, 9),
        "alpha" => $channels === 4 ? gzcompress($alphaRows, 9) : null
    ];
}

function streamStudentGradePdf($courseResults, $termsWithRecords, $studentName, $studentLrn, $studentFileName, $logoPath, $selectedTerm = null, $sectionLabel = null)
{
    $pageWidth = 842;
    $pageHeight = 595;
    $margin = 34;
    $pageContent = [];
    $currentPage = "";

    $logoImages = studentGradePdfPngImages($logoPath);
    $logoWidth = 0;
    $logoHeight = 0;
    if ($logoImages) {
        $logoWidth = $logoImages["width"];
        $logoHeight = $logoImages["height"];
    }

    $selectedTerm = in_array((int) $selectedTerm, [1, 2, 3], true) ? (int) $selectedTerm : 1;
    if ($sectionLabel === null) {
        $sectionLabel = "";
        foreach ($courseResults as $course) {
            if (!empty($course["section_name"])) {
                $sectionLabel = ((string) ($course["grade_level"] ?? "") !== "" ? "Grade " . $course["grade_level"] . " " : "") . (string) $course["section_name"];
                break;
            }
        }
    }
    $fixedWidths = [110, 280];
    $gradeColumnCount = 1;
    $gradeWidth = ($pageWidth - ($margin * 2) - array_sum($fixedWidths)) / max(1, $gradeColumnCount);
    $widths = array_merge($fixedWidths, array_fill(0, $gradeColumnCount, $gradeWidth));
    $headers = ["School Year", "Subject", "Grade"];
    $rowHeight = 34;

    $drawHeader = static function () use ($pageWidth, $pageHeight, $logoImages, $studentName, $studentLrn, $sectionLabel, $selectedTerm) {
        $content = "1 1 1 rg 0 0 {$pageWidth} {$pageHeight} re f\n";
        if ($logoImages !== null) {
            $content .= "q 58 0 0 58 " . (($pageWidth - 58) / 2) . " 518 cm /SchoolLogo Do Q\n";
        } else {
            $content .= "0.08 0.34 0.20 rg 393 530 56 40 re f\n";
            $content .= studentGradePdfText("SPI", 407, 545, "F2", 18, "1 1 1");
        }
        $content .= studentGradePdfText("ST. PEREGRINE INSTITUTE", 322, 506, "F2", 13, "0.08 0.30 0.18");
        $content .= studentGradePdfText("STUDENT GRADE REPORT", 348, 490, "F2", 9, "0.30 0.38 0.33");
        $content .= studentGradePdfText("Student: " . studentGradePdfTruncate($studentName ?: "Student", 55), 40, 466, "F2", 9);
        $content .= studentGradePdfText("Section: " . studentGradePdfTruncate($sectionLabel ?: "Not assigned", 60), 40, 451, "F1", 9);
        $content .= studentGradePdfText("LRN: " . ($studentLrn !== "" ? $studentLrn : "Not provided"), 620, 466, "F1", 9);
        $content .= studentGradePdfText("Term: " . $selectedTerm, 620, 451, "F1", 9);
        return $content;
    };

    $drawTableHeader = static function ($y) use (&$currentPage, $headers, $widths, $margin) {
        $x = $margin;
        $headerHeight = 26;
        foreach ($headers as $index => $label) {
            $width = $widths[$index];
            $currentPage .= "0.92 0.95 0.93 rg {$x} " . ($y - $headerHeight) . " {$width} {$headerHeight} re f\n";
            $currentPage .= "0.82 0.86 0.83 RG 0.55 w {$x} {$y} m " . ($x + $width) . " {$y} l S\n";
            $currentPage .= "0.82 0.86 0.83 RG 0.55 w {$x} " . ($y - $headerHeight) . " m " . ($x + $width) . " " . ($y - $headerHeight) . " l S\n";
            $currentPage .= "0.82 0.86 0.83 RG 0.55 w {$x} {$y} m {$x} " . ($y - $headerHeight) . " l S\n";
            $currentPage .= "0.82 0.86 0.83 RG 0.55 w " . ($x + $width) . " {$y} m " . ($x + $width) . " " . ($y - $headerHeight) . " l S\n";
            $maxChars = max(7, (int) floor(($width - 10) / 4.6));
            $label = studentGradePdfTruncate($label, $maxChars);
            $textX = $index === 0 ? $x + 7 : $x + max(4, ($width - (strlen($label) * 4.2)) / 2);
            $currentPage .= studentGradePdfText($label, $textX, $y - 16, "F2", 7.4, "0.12 0.20 0.15");
            $x += $width;
        }
        return $y - $headerHeight;
    };

    $currentPage = $drawHeader();
    $cursorY = $drawTableHeader(416);
    $rowIndex = 0;
    $finalGradeTotal = 0.0;
    $finalGradeCount = 0;
    foreach ($courseResults as $course) {
        if ($cursorY - $rowHeight < 46) {
            $pageContent[] = $currentPage . studentGradePdfText("Generated " . date("F j, Y"), 40, 24, "F1", 7, "0.42 0.48 0.44");
            $currentPage = $drawHeader();
            $cursorY = $drawTableHeader(416);
            $rowIndex = 0;
        }
        $termResult = $course["terms"][$selectedTerm] ?? null;
        $termGrade = !$termResult || empty($termResult["has_records"])
            ? null
            : ($termResult["initial_grade"] ?? null);
        $gradeLabel = !$termResult || empty($termResult["has_records"])
            ? "—"
            : (!$termResult["released"] ? "Not released" : ($termGrade === null ? "—" : number_format((float) $termGrade, 0)));
        $values = [
            (string) ($course["school_year"] ?? "—"),
            studentGradePdfTruncate($course["subject_name"], 52),
            $gradeLabel
        ];
        if ($termGrade !== null) {
            $finalGradeTotal += (float) $termGrade;
            $finalGradeCount++;
        }

        $x = $margin;
        $bottom = $cursorY - $rowHeight;
        foreach ($values as $index => $value) {
            $width = $widths[$index];
            if ($rowIndex % 2 === 1) $currentPage .= "0.96 0.97 0.97 rg {$x} {$bottom} {$width} {$rowHeight} re f\n";
            $currentPage .= "0.87 0.89 0.88 RG 0.45 w {$x} {$cursorY} m " . ($x + $width) . " {$cursorY} l S\n";
            $currentPage .= "0.87 0.89 0.88 RG 0.45 w {$x} {$bottom} m " . ($x + $width) . " {$bottom} l S\n";
            $currentPage .= "0.87 0.89 0.88 RG 0.45 w {$x} {$cursorY} m {$x} {$bottom} l S\n";
            $currentPage .= "0.87 0.89 0.88 RG 0.45 w " . ($x + $width) . " {$cursorY} m " . ($x + $width) . " {$bottom} l S\n";
            if ($index === count($values) - 1 && $termGrade !== null) {
                $pillWidth = min($width - 12, 48);
                $pillX = $x + ($width - $pillWidth) / 2;
                $currentPage .= "0.20 0.68 0.38 rg {$pillX} " . ($bottom + 10) . " {$pillWidth} 14 re f\n";
                $currentPage .= studentGradePdfText($value, $pillX + 5, $bottom + 14, "F2", 7.5, "1 1 1");
            } else {
                $display = studentGradePdfTruncate($value, max(6, (int) floor(($width - 10) / 4.2)));
                $textX = $index === 0 ? $x + 7 : $x + max(4, ($width - strlen($display) * 4.0) / 2);
                $currentPage .= studentGradePdfText($display, $textX, $bottom + 14, "F1", 7.2, "0.13 0.19 0.16");
            }
            $x += $width;
        }
        $cursorY = $bottom;
        $rowIndex++;
    }
    if ($finalGradeCount > 0) {
        $summaryHeight = 30;
        if ($cursorY - $summaryHeight < 46) {
            $pageContent[] = $currentPage . studentGradePdfText("Generated " . date("F j, Y"), 40, 24, "F1", 7, "0.42 0.48 0.44");
            $currentPage = $drawHeader();
            $cursorY = $drawTableHeader(416);
        }
        $summaryBottom = $cursorY - $summaryHeight;
        $tableWidth = array_sum($widths);
        $finalColumnWidth = $widths[count($widths) - 1];
        $summaryLabelX = $margin + $tableWidth - $finalColumnWidth - 66;
        $currentPage .= "0.92 0.95 0.93 rg {$margin} {$summaryBottom} {$tableWidth} {$summaryHeight} re f\n";
        $currentPage .= "0.82 0.86 0.83 RG 0.55 w {$margin} {$cursorY} m " . ($margin + $tableWidth) . " {$cursorY} l S\n";
        $currentPage .= "0.82 0.86 0.83 RG 0.55 w {$margin} {$summaryBottom} m " . ($margin + $tableWidth) . " {$summaryBottom} l S\n";
        $currentPage .= "0.82 0.86 0.83 RG 0.55 w " . ($margin + $tableWidth - $finalColumnWidth) . " {$cursorY} m " . ($margin + $tableWidth - $finalColumnWidth) . " {$summaryBottom} l S\n";
        $currentPage .= "0.82 0.86 0.83 RG 0.55 w " . ($margin + $tableWidth) . " {$cursorY} m " . ($margin + $tableWidth) . " {$summaryBottom} l S\n";
        $currentPage .= studentGradePdfText("Term average:", $summaryLabelX, $summaryBottom + 12, "F2", 8, "0.12 0.20 0.15");
        $average = number_format($finalGradeTotal / $finalGradeCount, 2);
        $averageX = $margin + $tableWidth - $finalColumnWidth + max(4, ($finalColumnWidth - strlen($average) * 4.2) / 2);
        $currentPage .= studentGradePdfText($average, $averageX, $summaryBottom + 12, "F2", 8, "0.12 0.20 0.15");
    }
    $pageContent[] = $currentPage . studentGradePdfText("Generated " . date("F j, Y"), 40, 24, "F1", 7, "0.42 0.48 0.44");

    $objects = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => "",
        3 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>"
    ];
    $nextObject = 5;
    $logoObjectId = null;
    if ($logoImages !== null) {
        $logoObjectId = $nextObject++;
        $alphaObjectId = null;
        if ($logoImages["alpha"] !== null) {
            $alphaObjectId = $nextObject++;
            $objects[$alphaObjectId] = "<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns {$logoWidth} >> /Length " . strlen($logoImages["alpha"]) . " >>\nstream\n" . $logoImages["alpha"] . "\nendstream";
        }
        $softMask = $alphaObjectId !== null ? " /SMask {$alphaObjectId} 0 R" : "";
        $objects[$logoObjectId] = "<< /Type /XObject /Subtype /Image /Width {$logoWidth} /Height {$logoHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns {$logoWidth} >>{$softMask} /Length " . strlen($logoImages["rgb"]) . " >>\nstream\n" . $logoImages["rgb"] . "\nendstream";
    }
    $pageIds = [];
    foreach ($pageContent as $content) {
        $contentId = $nextObject++;
        $pageId = $nextObject++;
        $pageIds[] = $pageId;
        $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $imageResource = $logoObjectId !== null ? " /XObject << /SchoolLogo {$logoObjectId} 0 R >>" : "";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$imageResource} >> /Contents {$contentId} 0 R >>";
    }
    $kids = implode(" ", array_map(static fn($pageId) => $pageId . " 0 R", $pageIds));
    $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pageIds) . " >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $objectNumber => $object) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= "{$objectNumber} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxObjectNumber = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObjectNumber + 1) . "\n0000000000 65535 f \n";
    for ($index = 1; $index <= $maxObjectNumber; $index++) {
        $pdf .= isset($offsets[$index]) ? sprintf("%010d 00000 n \n", $offsets[$index]) : "0000000000 00000 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObjectNumber + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    $filename = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $studentFileName) ?: "Student";
    $filename .= "_Grades.pdf";
    // PHP notices or template whitespace must never be prepended to the PDF bytes.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Content-Type: application/pdf");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Content-Length: " . strlen($pdf));
    echo $pdf;
    exit;
}
