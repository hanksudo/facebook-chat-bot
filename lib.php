<?php

// get current url
function current_url($no_qs=false) {
    $protocol = 'http';
    if ($_SERVER["HTTPS"] == "on") {$protocol .= "s";}
    $url = $protocol."://";

    $url .= $_SERVER["SERVER_NAME"];
    if ($_SERVER["SERVER_PORT"] != "80") {
        $url .= ":".$_SERVER["SERVER_PORT"];
    }

    if (!$no_qs) $url .= $_SERVER["REQUEST_URI"];
    else $url .= $_SERVER["PHP_SELF"];

    return $url;
}

// print_r friendly with ip limitation
function list_array($arr, $ip="")
  {
    if($ip!=="" && (getenv("REMOTE_ADDR")!==$ip)) return;
    
    echo "<pre>";
    print_r($arr);
    echo "</pre>";
  }

// Show config form when config not set
function show_config_form() {
    echo '<p>Note: Config empty! You can set all of there in config.php</p>';
    $form  = '<form action="" method="GET">';
    $form .= 'app_id: <input type="text" name="app_id" size="30"><br>';
    $form .= 'app_secret: <input type="text" name="app_secret" size="40"><br>';
    $form .= 'sender_uid: <input type="text" name="uid"><br>';
    $form .= '<input type="submit">';
    $form .= '</form>';
    echo $form;
}
