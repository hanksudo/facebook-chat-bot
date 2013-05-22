<?php
@include_once('config.php');
require_once('lib.php');

$STREAM_XML = '<stream:stream '.
    'xmlns:stream="http://etherx.jabber.org/streams" '.
    'version="1.0" xmlns="jabber:client" to="chat.facebook.com" '.
    'xml:lang="en" xmlns:xml="http://www.w3.org/XML/1998/namespace">';

$AUTH_XML = '<auth xmlns="urn:ietf:params:xml:ns:xmpp-sasl" '.
    'mechanism="X-FACEBOOK-PLATFORM"></auth>';

$CLOSE_XML = '</stream:stream>';

$RESOURCE_XML = '<iq type="set" id="3">'.
    '<bind xmlns="urn:ietf:params:xml:ns:xmpp-bind">'.
    '<resource>fb_xmpp_script</resource></bind></iq>';

$SESSION_XML = '<iq type="set" id="4" to="chat.facebook.com">'.
    '<session xmlns="urn:ietf:params:xml:ns:xmpp-session"/></iq>';

$START_TLS = '<starttls xmlns="urn:ietf:params:xml:ns:xmpp-tls"/>';

$MESSAGE_XML = '<message type="chat" from="-%s@chat.facebook.com" to="-%s@chat.facebook.com">'.
    '<body>%s</body></message>';

function open_connection($server) {
    echo "[INFO] Opening connection... ";

    $fp = fsockopen($server, 5222, $errno, $errstr);
    if (!$fp) {
        echo "$errstr ($errno)<br>";
    } else {
        echo "connnection open<br>";
    }

    return $fp;
}

function send_xml($fp, $xml) {
    fwrite($fp, $xml);
}

function recv_xml($fp,  $size=4096) {
    $xml = fread($fp, $size);
    if ($xml === "") {
        return null;
    }

    // parses xml
    $xml_parser = xml_parser_create();
    xml_parse_into_struct($xml_parser, $xml, $val, $index);
    xml_parser_free($xml_parser);

    return array($val, $index);
}

function find_xmpp($fp,  $tag, $value=null, &$ret=null) {
    static $val = null, $index = null;

    do {
        if ($val === null && $index === null) {
            list($val, $index) = recv_xml($fp);
            if ($val === null || $index === null) {
                return false;
            }
        }

        foreach ($index as $tag_key => $tag_array) {
            if ($tag_key === $tag) {
                if ($value === null) {
                    if (isset($val[$tag_array[0]]['value'])) {
                        $ret = $val[$tag_array[0]]['value'];
                    }
                    return true;
                }
                foreach ($tag_array as $i => $pos) {
                    if ($val[$pos]['tag'] === $tag && isset($val[$pos]['value']) && $val[$pos]['value'] === $value) {
                        $ret = $val[$pos]['value'];
                        return true;
                    }
                }
            }
        }
        
        $val = $index = null;

    } while (!feof($fp));

    return false;
}

function xmpp_connect($options, $access_token) {
    global $STREAM_XML, $AUTH_XML, $RESOURCE_XML, $SESSION_XML, $CLOSE_XML, $START_TLS;

    $fp = open_connection($options['server']);
    if (!$fp) {
        return false;
    }

    // initiates auth process (using X-FACEBOOK_PLATFORM)
    send_xml($fp,  $STREAM_XML);
    if (!find_xmpp($fp, 'STREAM:STREAM')) {
        return false;
    }
    if (!find_xmpp($fp,  'MECHANISM', 'X-FACEBOOK-PLATFORM')) {
        return false;
    }

    // starting tls - MANDATORY TO USE OAUTH TOKEN!!!!
    send_xml($fp,  $START_TLS);
    if (!find_xmpp($fp, 'PROCEED', null, $proceed)) {
        return false;
    }
    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

    send_xml($fp, $STREAM_XML);
    if (!find_xmpp($fp, 'STREAM:STREAM')) {
        return false;
    }
    if (!find_xmpp($fp, 'MECHANISM', 'X-FACEBOOK-PLATFORM')) {
        return false;
    }

    // gets challenge from server and decode it
    send_xml($fp, $AUTH_XML);
    if (!find_xmpp($fp,  'CHALLENGE', null, $challenge)) {
        return false;
    }
    $challenge = base64_decode($challenge);
    $challenge = urldecode($challenge);
    parse_str($challenge, $challenge_array);

    // creates the response array
    $resp_array = array(
        'method' => $challenge_array['method'],
        'nonce' => $challenge_array['nonce'],
        'access_token' => $access_token,
        'api_key' => $options['app_id'],
        'call_id' => 0,
        'v' => '1.0',
    );

    // creates signature
    $response = http_build_query($resp_array);

    // sends the response and waits for success
    $xml = '<response xmlns="urn:ietf:params:xml:ns:xmpp-sasl">'.base64_encode($response).'</response>';
    send_xml($fp, $xml);
    if (!find_xmpp($fp, 'SUCCESS')) return false;
    
    // finishes auth process
    send_xml($fp, $STREAM_XML);
    if (!find_xmpp($fp,'STREAM:STREAM')) return false;
    if (!find_xmpp($fp, 'STREAM:FEATURES')) return false;
    
    send_xml($fp, $RESOURCE_XML);
    if (!find_xmpp($fp, 'JID')) return false;
    
    send_xml($fp, $SESSION_XML);
    if (!find_xmpp($fp, 'SESSION')) return false;
    
    echo ("<p>Authentication complete</p>");

    return $fp;
}

function xmpp_send_msg($fp, $options) {
    global $CLOSE_XML, $MESSAGE_XML;

    echo 'Sent message: <strong>'.$options['msg'].'</strong>, from '.$options['uid'].' to '.$options['recv_id'];

    send_xml($fp, sprintf($MESSAGE_XML, $options['uid'], $options['recv_id'], $options['msg']));
    send_xml($fp, $CLOSE_XML);
}

//Gets access_token with xmpp_login permission
function get_access_token($app_id, $app_secret, $my_url){ 

    if( !isset($_REQUEST["code"]) && empty($_REQUEST["code"]) ) {
        $dialog_url = "https://www.facebook.com/dialog/oauth?scope=xmpp_login".
            "&client_id=".$app_id.
            "&redirect_uri=".urlencode($my_url);

        echo("<script>top.location.href='".$dialog_url."'</script>");
        exit();
    }

    $token_url = "https://graph.facebook.com/oauth/access_token?".
        "client_id=".$app_id."&redirect_uri=".urlencode($my_url).
        "&client_secret=" . $app_secret.
        "&code=" . $_REQUEST["code"];
        
    $access_token = @file_get_contents($token_url);
    parse_str($access_token, $output);

    return($output['access_token']);
}

function _main() {
    global $app_id, $app_secret, $uid, $recv_id, $access_token;
    echo "<h2>Facebook Chat Bot</h2>";
  
    if (isset($_GET['app_id'])) $app_id = $_GET['app_id'];
    if (isset($_GET['app_secret'])) $app_secret = $_GET['app_secret'];
    if (isset($_GET['uid'])) $uid = $_GET['uid'];
    if (isset($_GET['recv_id'])) $recv_id = $_GET['recv_id'];

    // if app_id or app_secret not set, show config form.
    if ( empty($app_id) || empty($app_secret) ) {
        show_config_form();
        exit();
    }
  
    // get access_token if not set
    if ( !isset($access_token) || empty($access_token) ) {
        echo '<p>Trying to getting access_token ... ';

        $access_token = get_access_token($app_id, $app_secret, current_url());

        if (empty($access_token)) {
            unset($_GET['code']);
            echo("<script>top.location.href='" . current_url(true) . '?' . http_build_query($_GET) . "'</script>");
            exit();
        }
        echo 'Done.</p>';
        echo '<p>access_token: '.$access_token.'</p>';
    }

    // get uid from Facebook /me
    if (empty($uid)) {
        $json_me = json_decode(file_get_contents('https://graph.facebook.com/me?access_token='.$access_token));
        $uid = $json_me->id;
        echo "<p>uid empty, get uid from Graph API. uid= ".$uid."</p>";
    }

    // if reciver empty, receiver will be sender.
    if (empty($recv_id)) $recv_id = $uid;

      
    // connect to XMPP Authentication
    $server_options = array(
        'app_id' => $app_id,
        'server' => 'chat.facebook.com'
    );
    $fp = xmpp_connect($server_options, $access_token);

    // send message
    if ($fp) {
        $msg_options = array(
            'uid' => $uid,
            'recv_id' => $recv_id,
            'msg' => 'I am robot.'.date("Y-m-d H:i:s")
        );  
        xmpp_send_msg($fp, $msg_options);
    } else {
        echo "An error ocurred<br>";
    }

    fclose($fp);
}

_main();