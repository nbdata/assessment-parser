<?php
include 'vendor/autoload.php';

$parser = new \Smalot\PdfParser\Parser();
$years = array('2016', '2017', '2018', '2019');
$entries = array();

// Recent Sales info
$sales_files = array('single.pdf', 'multi.pdf');
foreach ($sales_files AS $file) {  
  $pdf    = $parser->parseFile($file);
  $pages  = $pdf->getPages();
  foreach ($pages as $page) {
    $blob = $page->getText();
    $lines = explode("\n", $blob);

    foreach (array_reverse($lines) AS $line) {
      if (strlen ($line) > 70) {
        $tmp = preg_split('/\s+/', $line);
        $id = trim($tmp[0]);

        if ($file == 'multi.pdf') {
          $date = trim($tmp[3]);
          $price = clean($tmp[4]);
          $assess = clean($tmp[5]);
        }
        else {
          $date = trim($tmp[1]);
          $price = clean($tmp[2]);
          $assess = clean($tmp[3]);

          // One parse error on 328 North St
          if ($assess == '345000') {
            $price = '345000';
            $date = '6/10/2013';
            $assess = '390100';
          }
        }

        // Dont overwrite newer sales data with older data
        if (!isset($entries[$id])) {
          $entries[$id] = array('saledate' => $date, 'saleprice' => $price, 'saleassess' => $assess);
        }
      }
    }
  }
}

// Add in info from geocoding
$handle = fopen('GeocodeResults.csv', 'r');
while ($row = fgetcsv($handle, 1000, ',')) {
  if (count($row) < 10) continue;
  
  $id = str_replace('/', '-', $row[0]);
  $z = array();
  foreach (explode('-', $id) AS $piece) {
    $z[] = (int) $piece;
  }
  $id = implode('-', $z);
  $tmp = explode(',', $row[5]);
  $entries[$id]['latitude'] = $tmp[1];
  $entries[$id]['longitude'] = $tmp[0];
  $entries[$id]['tiger'] = $row[6];
} 
fclose($handle);

// Add in info from geocoding
$handle = fopen('AA_Parcels.csv', 'r');
while ($row = fgetcsv($handle, 1000, ',')) {
  if (count($row) < 10) continue;

  $id = $row[5];
  $entries[$id]['zoning'] = $row[13];
  $entries[$id]['sqft'] = $row[24];
  $entries[$id]['land_size'] = $row[25];
  $entries[$id]['year_built'] = $row[23];
  $entries[$id]['bathrooms'] = $row[29];
  $entries[$id]['bedrooms'] = $row[30];
  $entries[$id]['ward'] = $row[31];
  $entries[$id]['census_block'] = $row[33];
}
fclose($handle);

foreach ($years AS $year) {
  $pdf    = $parser->parseFile($year . '.pdf');
  $pages  = $pdf->getPages();

  $id = null;

  foreach ($pages as $page) {
    $blob = $page->getText();
    $lines = explode("\n", $blob);

    foreach ($lines AS $line) {
      if (strpos($line, '*******************************************************************************************************') === 0) {
        $id = trim(str_replace('*', '', $line));
        if (!$id) print('Could not find id in ' . $line);
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
        if ($repeat_id !== $id) print "The second id $repeat_id does not match first id: $id \n";
        $entries[$id]['code'] = trim(substr($line, 31, 3));
      }
      else if (strpos($line, 'TAXABLE VALUE') !== FALSE && strpos($line, 'CITY') !== FALSE) {
        $entries[$id][$year . 'taxvalue'] = str_replace(',', '', trim(substr($line, 100, 18)));
      }
      else if (strpos($line, 'FULL MARKET VALUE') > 0) {
        $tmp = explode(" ", str_replace(',', '', trim(substr($line, 50, 23))));
        $entries[$id][$year . 'fullmarket'] = $tmp[0];
      }

      // Try to see if the owner address matches the physical address
      if ($cnt > 2) {
        $first_fifteen = trim(substr($line, 0, 14));
        //print "$first_fifteen::" . $entries[$id]['address'] . "::" . stripos($entries[$id]['address'], $first_fifteen) . "\n";
        //var_dump($line);
        if (stripos($entries[$id]['address'], $first_fifteen) === 0) {
          $entries[$id]['owneraddress'] = 1;
        }
        else if (!isset($entries[$id]['owneraddress']) && stripos($first_fifteen, 'Newburgh') !== FALSE) {
          $entries[$id]['owneraddress'] = 2;
        }
        else if (!isset($entries[$id]['owneraddress']) && strpos($first_fifteen, ' NY') !== FALSE) {
          $entries[$id]['owneraddress'] = 3;
        }
      }

      $cnt++;
    }
  }
}

// Sort by id
ksort($entries);

$fp = fopen('output.csv', 'w');
fputcsv($fp, array('ID', 'Street', 'Non-Homestead', 'Account', 'Code', '2016 City', '2016 Full', '2017 City', '2017 Full', '2018 City', '2018 Full', '2019 City', '2019 Full', 'Sale Date', 'Sale Price', 'Sale AV', 
  'OwnerAtAddress', 'Latitude', 'Longitude', 'TigerLine', '20162019Diff', 'SaleAssessDiff',
  'SqFt', 'Bathrooms', 'Bedrooms', 'YearBuilt', 'LandSize', 'Zoning', 'Ward', 'CensusBlock'
));
foreach ($entries AS $id => $data) {
  if (!isset($data['2019fullmarket'])) continue;

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
    $data['saledate'],
    $data['saleprice'],
    $data['saleassess'],
    isset($data['owneraddress']) ? $data['owneraddress'] : 0, 
    $data['latitude'],
    $data['longitude'],
    $data['tiger'],
    (isset($data['2016fullmarket']) && isset($data['2019fullmarket'])) ? (int) $data['2019fullmarket'] - (int) $data['2016fullmarket'] : '',
    (isset($data['saleprice']) && isset($data['saleassess'])) ? (int) $data['saleprice'] - (int) $data['saleassess'] : '',
    $data['sqft'],
    $data['bathrooms'],
    $data['bedrooms'],
    $data['year_built'],
    $data['land_size'],
    $data['zoning'],
    $data['ward'],
    $data['census_block'],

  ));
}

function clean($str) {
  $tmp = trim($str);
  return preg_replace("/[^0-9.]/", "", $tmp);
}
