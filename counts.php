<?php
$m = new Mongo();
$db = $m->selectDB('et');

$lists = array(
  21016203 => 'The Economist audio edition',
  21016204 => 'New on The Economist online',
  21016205 => "Editor's Highlights",
  21016206 => "Publisher's Newsletter",
  21016207 => 'Management Thinking',
  21016208 => 'The World This Week: Politics',
  21016209 => 'The World This Week: Business',
  21016210 => "Gulliver's Best",
  21016211 => 'Economist Debates',
);

foreach ($lists as $nid => $title) {
  echo number_format($db->suppressions->count(array($nid => TRUE))) . ' users will be removed from ' . $title . PHP_EOL;
}
