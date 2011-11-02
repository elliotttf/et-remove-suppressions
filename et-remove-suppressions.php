<?php

function et_remove_suppressions($sup_list, $newsletter, $request_id = NULL) {
  $properties = array('ID', 'Email Address', 'Drupal ID');
  $results = ETUtils::instance()->search(
    'DataExtensionObject[' . $sup_list . ']',
    EC_EXACT_TARGET_CLIENT_ID,
    $properties,
    NULL,
    TRUE,
    $request_id
  );
  if ($results->OverallStatus == 'OK' || $results->OverallStatus == 'MoreDataAvailable') {
    foreach ($results->Results as $result) {
      print_r($result);
    }
  }
  if ($results->OverallStatus == 'MoreDataAvailable') {
    et_remove_suppressions($sup_list, $newsletter, $results->RequestID);
  }
}

et_remove_suppressions('Gullivers best Unsubscribes', 'Gullivers best');
