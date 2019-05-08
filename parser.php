<?php
include 'vendor/autoload.php';

$years = array('2016', '2017', '2018', '2019');
$entries = array();

foreach ($years AS $year) {
  // Parse pdf file and build necessary objects.
  $parser = new \Smalot\PdfParser\Parser();
  $pdf    = $parser->parseFile($year . '.pdf');
  
  // Retrieve all pages from the pdf file.
  $pages  = $pdf->getPages();

  $id = null;

  // Loop over each page to extract text.
  foreach ($pages as $page) {
    $blob = $page->getText();
    $lines = explode("\n", $blob);

    foreach ($lines AS $line) {
      if (strpos($line, '*******************************************************************************************************') === 0) {
        $id = trim(str_replace('*', '', $line));
        if (!$id) {
          //print('Could not find id in ' . $line);
        }
        $cnt = 0;
      }
      else if ($cnt == 1) {
        $entries[$id]['address'] = trim(substr($line, 20, 35));
        $hmstd = substr($line, 56, 26);
        $entries[$id]['nonhomestead'] = (strpos($hmstd, 'NON-HOME') !== FALSE) ? 1 : ((strpos($hmstd, 'NON-HMST') !== FALSE) ? 2 : 0);
        $entries[$id]['account'] = trim(substr($line, 110, 17));
      }
      else if ($cnt == 2) {
        $repeat_id = trim(substr($line, 0, 20));
        if ($repeat_id !== $id) {
          //print "The second id $repeat_id does not match first id: $id \n";
        }
        $entries[$id]['code'] = trim(substr($line, 31, 3));
      }
      else if (strpos($line, 'TAXABLE VALUE') !== FALSE && strpos($line, 'CITY') !== FALSE) {
        $entries[$id][$year . 'taxvalue'] = str_replace(',', '', trim(substr($line, 100, 18)));
      }
      else if (strpos($line, 'FULL MARKET VALUE') > 0) {
        $tmp = explode(" ", str_replace(',', '', trim(substr($line, 50, 23))));
        $entries[$id][$year . 'fullmarket'] = $tmp[0];
        if ($entries[$id][$year . 'fullmarket'] == '100300   SC') {
          var_dump($line);die();
        }
      }
      $cnt++;
    }
  }
}

$fp = fopen('output.csv', 'w');
fputcsv($fp, array('ID', 'Street', 'Non-Homestead', 'Account', 'Code', '2016 City', '2016 Full', '2017 City', '2017 Full', '2018 City', '2018 Full', '2019 City', '2019 Full'));
foreach ($entries AS $id => $data) {
  fputcsv($fp, array(
    $id, 
    $data['address'],
    $data['nonhomestead'],
    $data['account'],
    $data['code'],
    $data['2016taxvalue'],
    $data['2016fullmarket'],
    $data['2017taxvalue'],
    $data['2017fullmarket'],
    $data['2018taxvalue'],
    $data['2018fullmarket'],
    $data['2019taxvalue'],
    $data['2019fullmarket'],
  ));
}