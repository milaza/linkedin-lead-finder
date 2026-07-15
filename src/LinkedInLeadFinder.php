#!/usr/bin/php5
<?php
use simple_curl\curl;

require('inc/config.php');
require_once 'inc/functions.php';

require_once 'inc/curl.php';


$headers = (array(
    'Authorization: Bearer ' . $apify_api_key,
    'Content-Type: application/json; charset=utf-8'
));

$serialized = $argv[1];
$postarr = unserialize($serialized); 
//print_r($postarr);
$max_item = $postarr['maxItems'];
$list_id = $postarr['listid'];
$user_id = $postarr['user_id'];
unset($postarr['listid']);
$persent = 0;
set_counter($list_id, $persent);
$row_count = 0;
$email_count = 0;
$identifier = array();
if (!empty($postarr['email']) && $postarr['email'][0] == 'on') $eml_coef = 0.5; else $eml_coef = 1;

$sleep = max(3, floor($max_item/3));
$pg =  ceil($max_item/25);
$persent_coef = 1/ceil($max_item/100);
$current_count = 0;

for ($j = 1; $j <= $pg; $j+=4) {
$postarr['startPage'] = $j;
$postarr['takePages'] = min(4, $pg - $j + 1);
$postarr['maxItems'] = min(100, $max_item - ($j-1)*25);

$url = 'https://api.apify.com/v2/acts/harvestapi~linkedin-profile-search/runs';
$query = $postarr;

curl::prepare($url, $query, $headers, true);
curl::exec_post();
$run_response = curl::get_response_assoc();

$i = 1;
while($i <= 10) {
if (!empty($run_response['data']) && $run_response['data']['status'] == 'READY' && $run_response['data']['id'] != '') {
   $run_id = $run_response['data']['id'];
   $persent+= floor(10*$persent_coef)*$eml_coef;
   set_counter($list_id, $persent);
   break;
}
  if ($i == 10) { set_counter($list_id, '-2'); exit; }
  sleep(3*$i);
  curl::exec_post();
  $run_response = curl::get_response_assoc();
  $i++;
}
//error_log('@@@'.$run_id);
sleep($sleep);

$url = 'https://api.apify.com/v2/actor-runs/' . $run_id;
$query = array();
curl::prepare($url, $query, $headers, true);
curl::exec_get();
$run_response = curl::get_response_assoc();

//print_r($run_response); exit;
$i = 1;
while($i <= 20) {
if (!empty($run_response['data']) && $run_response['data']['status'] == 'SUCCEEDED' && $run_response['data']['defaultDatasetId']!= '') {
   $dataset_id = $run_response['data']['defaultDatasetId'];
   //$total_record = $run_response['data']['chargedEventCounts']['full-profile'];
   $persent+= floor(40*$persent_coef)*$eml_coef;
   set_counter($list_id, $persent);
   break;
}
  if ($i == 20) { set_counter($list_id, '-2'); exit; }
  $persent+= floor(5*$persent_coef)*$eml_coef;
  set_counter($list_id, $persent);
  sleep($sleep);
  curl::exec_get();
  $run_response = curl::get_response_assoc();
  $i++;
}

  sleep(5);
//error_log('$$$'.$dataset_id);
$url = 'https://api.apify.com/v2/datasets/' . $dataset_id . '/items';
$query = array();
curl::prepare($url, $query, $headers, true);
curl::exec_get();
$run_response = curl::get_response_assoc();
//error_log('***' . print_r($run_response, TRUE));
$i = 1;
while($i <= 10) {
if (!empty($run_response)) {
   if ($run_response[0]['_meta']['pagination']['totalElements'] > 0) {
     $cnt_record = min($postarr['maxItems'], $run_response[0]['_meta']['pagination']['totalElements']);
     for ($k = 0; $k < $cnt_record; $k++) {
        add_record($list_id, $run_response[$k]);
        $page_percent = 50*$persent_coef*(($k+1)/$cnt_record)*$eml_coef;
        set_counter($list_id, ceil($persent + $page_percent));
        $identifier[] = $run_response[$k]['publicIdentifier'];
        fillEmptyFieldsByPublicIdentifier($run_response[$k]['publicIdentifier']);
     }
     $persent += $page_percent;
   }
   else if ($run_response[0]['_meta']['pagination']['totalElements'] == 0) {
    set_counter($list_id, '-1');
   }
   break;
}
else {
    set_counter($list_id, '-1');
    $result = mysqli_query($con, "DELETE FROM lists WHERE id = " . $list_id);
    break;
}
  if ($i == 10) { set_counter($list_id, '-2'); exit; }
  sleep(5);
  curl::exec_get();
  $run_response = curl::get_response_assoc();
  $i++;
}
sleep(20);
}
if (!empty($postarr['email']) && $postarr['email'][0] == 'on') {

$headers2 = (array(
    'Authorization: Bearer ' . $salesql_api_key,
    'Content-Type: application/json; charset=utf-8'
));
$cnt_persn = count($identifier);

if ($cnt_persn > 0) {
$r = 0;
foreach ($identifier as $persn) {
$data_upd = array();

$record_data = get_record_data($persn);
if ($record_data['email'] != '') {
  $email_count+= intval(substr_count(trim(str_replace("\r", "", $record_data['email'])), "\n") + 1);
}
else {
$f = 0;
$url2 = 'https://api-public.salesql.com/v1/persons/enrich/?linkedin_url=https%3A%2F%2Fwww.linkedin.com%2Fin%2F' . encode_slug_cyr($persn) . '%2F';
$query2 = array();
curl::prepare($url2, $query2, $headers2, true);
while($f <= 10) {
curl::exec_get();
$run_response = curl::get_response_assoc();
if (!empty($run_response)) {
//error_log( print_r($run_response, true ));
   if ($run_response['uuid'] != '') {
     if (!empty($run_response['emails'])) {
     $record_email = array();
     $cnt_email = count($run_response['emails']);
     for ($m = 0; $m < $cnt_email; $m++) {
         $record_email[] = $run_response['emails'][$m]['email'];
     }
     if (count($record_email)>0) add_record_email($persn, implode("\r\n", $record_email));
     }

      if (!empty($run_response['first_name'])) $data_upd['firstName'] = $run_response['first_name'];
      if (!empty($run_response['last_name'])) $data_upd['lastName'] = $run_response['last_name'];
      if (!empty($run_response['image'])) {
      $parts = explode('?', $run_response['image']);
      $avatar = $parts[0];
      $data_upd['avatar'] = $avatar;
      }
      if (!empty($run_response['headline'])) $data_upd['headline'] = $run_response['headline'];
      if (!empty($run_response['title'])) $data_upd['about'] = $run_response['title'];
      if (!empty($run_response['organization']['name'])) $data_upd['companyName'] = $run_response['organization']['name'];
      if (count($data_upd)>0) update_record($persn, $data_upd);
   }
   break;
   }
   $f++;   
   }
  sleep(5);
}
$page_percent = 50*$persent_coef*(($r+1)/$cnt_persn)*$eml_coef;
set_counter($list_id, min(99, ceil($persent + $page_percent)));
$r++;
$persent += $page_percent;
}
}
}
if ($row_count > 0) {
    $credit_cnt = $row_count;
    $result = mysqli_query($con, "INSERT INTO credits (id, user_id, summa, date, list_id, row_count, with_email, status) VALUES ('', " . $user_id . ", " . ($credit_cnt + $email_count) . ", NOW(), " . $list_id . ", " . $row_count . ", " . $email_count . ", 0)");    
    $result = mysqli_query($con, "UPDATE users SET credit = credit - " . $credit_cnt . " - " . $email_count . " WHERE id = " . $user_id);    
}
$persent = 100;
set_counter($list_id, $persent);

function set_counter($list_id, $count){
    global $settings_arr;
    global $con;
    global $user_id;

//error_log("INSERT INTO counter (id, list_id, user_id, counter) VALUES ('', " . $list_id . ", " . $user_id . ", " . $count . ") ON DUPLICATE KEY UPDATE counter=" . $count);    
    $count = min(100, $count);

    $result = mysqli_query($con, "INSERT INTO counter (id, list_id, user_id, counter) VALUES ('', " . $list_id . ", " . $user_id . ", " . $count . ") ON DUPLICATE KEY UPDATE counter=" . $count);    
    return true;
}

function esc($con, $str) {
    return $con->real_escape_string($str);
}

function add_record($list_id, $data){
    global $con;
    global $row_count;

$sql = "
INSERT INTO records (
    id, list_id, publicIdentifier, avatar, firstName, lastName, headline,
    followerCount, companyName, country, about, openToWork, hiring, influencer,
    countryCode, verified, topSkills, connectionsCount, experiencePosition,
    experienceLocation, experienceEmploymentType, experienceWorkplaceType,
    experienceCompanyName, experienceCompanyLinkedinUrl, experienceDuration,
    experienceDescription, experienceSkills, experienceStartDate, experienceEndDate,
    schoolName, schoolLinkedinUrl, degree, fieldOfStudy, educationSkills,
    educationStartDate, educationEndDate, certifications, projects, volunteering,
    skills, positions, languages
) VALUES (
    '',
    " . (int)$list_id . ",
    '" . esc($con, $data['publicIdentifier']) . "',
    '" . esc($con, $data['photo']) . "',
    '" . esc($con, $data['firstName']) . "',
    '" . esc($con, $data['lastName']) . "',
    '" . esc($con, $data['headline']) . "',
    '" . esc($con, $data['followerCount']) . "',
    '" . esc($con, $data['currentPosition'][0]['companyName']) . "',
    '" . esc($con, $data['location']['linkedinText']) . "',
    '" . esc($con, $data['about']) . "',
    '" . esc($con, $data['openToWork']) . "',
    '" . esc($con, $data['hiring']) . "',
    '" . esc($con, $data['influencer']) . "',
    '" . esc($con, $data['location']['countryCode']) . "',
    '" . esc($con, $data['verified']) . "',
    '" . esc($con, $data['topSkills']) . "',
    '" . esc($con, $data['connectionsCount']) . "',
    '" . esc($con, $data['experience'][0]['position']) . "',
    '" . esc($con, $data['experience'][0]['location']) . "',
    '" . esc($con, $data['experience'][0]['employmentType']) . "',
    '" . esc($con, $data['experience'][0]['workplaceType']) . "',
    '" . esc($con, $data['experience'][0]['companyName']) . "',
    '" . esc($con, $data['experience'][0]['companyLinkedinUrl']) . "',
    '" . esc($con, $data['experience'][0]['duration']) . "',
    '" . esc($con, $data['experience'][0]['description']) . "',
    '" . esc($con, implode(', ', $data['experience'][0]['skills'])) . "',
    '" . esc($con, $data['experience'][0]['startDate']['text']) . "',
    '" . esc($con, $data['experience'][0]['endDate']['text']) . "',
    '" . esc($con, $data['education'][0]['schoolName']) . "',
    '" . esc($con, $data['education'][0]['schoolLinkedinUrl']) . "',
    '" . esc($con, $data['education'][0]['degree']) . "',
    '" . esc($con, $data['education'][0]['fieldOfStudy']) . "',
    '" . esc($con, implode(', ', $data['education'][0]['skills'])) . "',
    '" . esc($con, $data['education'][0]['startDate']['text']) . "',
    '" . esc($con, $data['education'][0]['endDate']['text']) . "',
    '" . esc($con, implode(', ', array_unique(array_map(function($v){ return $v['title']; }, $data['certifications'])))) . "',
    '" . esc($con, implode(', ', array_unique(array_map(function($v){ return $v['title']; }, $data['projects'])))) . "',
    '" . esc($con, implode(', ', array_unique(array_map(function($v){ return $v['description']; }, $data['volunteering'])))) . "',
    '" . esc($con, implode(', ', array_unique(array_map(function($v){ return $v['name']; }, $data['skills'])))) . "',
    '" . esc($con, implode_positions($data['skills'])) . "',
    '" . esc($con, implode(', ', array_map(function($v){ return $v['name'].' - '.$v['proficiency']; }, $data['languages']))) . "'
) ON DUPLICATE KEY UPDATE id=id";

        
$result = mysqli_query($con, $sql);    
if (mysqli_affected_rows($con) === 1) {
    $row_count++;
}
return true;
}

function implode_positions($skills, $glue = ', ') {
    $out = array();

    if (!is_array($skills)) return '';

    foreach ($skills as $skill) {
        if (!isset($skill['positions']) || $skill['positions'] === null) continue;

        if (is_array($skill['positions'])) {
            foreach ($skill['positions'] as $p) {
                if ($p !== '' && $p !== null) $out[] = $p;
            }
        } else {
            if ($skill['positions'] !== '') $out[] = $skill['positions'];
        }
    }
    $out = array_unique(array_filter($out, 'strlen'));

    return implode($glue, $out);
}

function add_record_email($identifier, $email) {
    global $con;  
    global $email_count;

    $result = mysqli_query($con, "UPDATE records SET email = '" . $email ."' WHERE publicIdentifier = '" . $identifier . "' AND email is NULL");
    if(mysqli_affected_rows($con) > 0){
       $email_count+= intval(substr_count(trim(str_replace("\r", "", $email)), "\n") + 1);
       return true;
    }
    else return false;

}

function fillEmptyFieldsByPublicIdentifier($publicIdentifier) {
    global $con;

    if (!$con) {
        return false;
    }

    $publicIdentifierEsc = mysqli_real_escape_string($con, $publicIdentifier);

    $sql = "SELECT * FROM records WHERE publicIdentifier = '" . $publicIdentifierEsc . "' ORDER BY id DESC";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        return false;
    }

    $records = array();
    while ($row = mysqli_fetch_assoc($res)) {
        $records[] = $row;
    }

    if (count($records) < 2) {
        return true; 
    }

    $fields = array_keys($records[0]);
    $fields = array_diff($fields, array('id'));

    $latest = array();
    foreach ($records as $rec) {
        foreach ($fields as $f) {
            if (!isset($latest[$f])) {
                if ($rec[$f] !== null && trim((string)$rec[$f]) !== '') {
                    $latest[$f] = $rec[$f];
                }
            }
        }
        if (count($latest) === count($fields)) {
            break;
        }
    }

    if (empty($latest)) {
        return true;
    }

    $oldAutocommit = mysqli_autocommit($con, FALSE);
    $allOk = true;

    foreach ($records as $rec) {
        $id = intval($rec['id']);
        $setParts = array();

        foreach ($fields as $f) {
            $cur = $rec[$f];
            if ($cur === null || trim((string)$cur) === '') {
                if (isset($latest[$f])) {
                    $escaped = mysqli_real_escape_string($con, $latest[$f]);
                    $setParts[] = "`" . $f . "` = '" . $escaped . "'";
                }
            }
        }

        if (!empty($setParts)) {
            $updateSql = "UPDATE records SET " . implode(", ", $setParts) . " WHERE id = " . $id;
            if (!mysqli_query($con, $updateSql)) {
                $allOk = false;
                break;
            }
        }
    }

    if ($allOk) {
        if (!mysqli_commit($con)) {
            mysqli_rollback($con);
            mysqli_autocommit($con, $oldAutocommit);
            return false;
        }
    } else {
        mysqli_rollback($con);
        mysqli_autocommit($con, $oldAutocommit);
        return false;
    }

    mysqli_autocommit($con, $oldAutocommit ? TRUE : FALSE);

    return true;
}

function update_record($publicIdentifier, $data) {
    global $con;

$setParts = array();
foreach ($data as $col => $val) {
    $safeVal = "'" . mysqli_real_escape_string($con, $val) . "'";
    $setParts[] = "$col = CASE WHEN $col IS NULL OR $col = '' THEN $safeVal ELSE $col END";
}

$sql = "UPDATE records SET " . implode(", ", $setParts) . " WHERE publicIdentifier = '" . $publicIdentifier . "'";
error_log($sql);
mysqli_query($con, $sql);
return true;
}

?>
