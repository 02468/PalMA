<?php

// Copyright (C) 2014 Universitätsbibliothek Mannheim
// See file LICENSE for license details.

// Authors: Alexander Wagner, Stefan Weil

// TODO: Authentisierung. Funktioniert hier nicht mit auth.php,
// daher vielleicht über Datenbankabfrage.

// Test whether the script was called directly (used for unit test). Use some
// heuristics to detect whether we are not running in a web application.
if (isset($unittest)) {
    $unittest = array();
}
$unittest[__FILE__] = !isset($_SERVER['SERVER_NAME']);

// initialize database
require_once('DBConnector.class.php');
$dbcon = new DBConnector();

if (!$unittest[__FILE__]) {
    trace("QUERY_STRING=" . $_SERVER['QUERY_STRING']);
}

// TODO: Get display dimensions automatically:
// xdpyinfo
//   dimensions:    1600x900 pixels (423x238 millimeters)
// xrandr
//   Screen 0: minimum 320 x 200, current 1600 x 900, maximum 8192 x 8192
// xwininfo -root
//   -geometry 1600x900+0+0

if (file_exists('palma.ini') && !$unittest[__FILE__]) {
    // Get configuration from ini file.
    if (isset($_SERVER['HTTP_REFERER'])) {
        $url = dirname($_SERVER['HTTP_REFERER']) . '/' . basename($_SERVER['PHP_SELF']);
        trace("alt = $url");
    }
    $conf = parse_ini_file("palma.ini", true);
    $display = $conf['display']['id'];
    if (isset($conf['display']['ssh'])) {
        $ssh = $conf['display']['ssh'];
    }
    $url = $conf['path']['control_file'];
    trace("url = $url");
} else {
    // Guess configuration.
    $display = ':1';
    if (!$unittest[__FILE__]) {
        $url = dirname($_SERVER['HTTP_REFERER']) . '/' . basename($_SERVER['PHP_SELF']);
        trace("url = $url");
    }
}

function displayCommand($cmd) {
    global $display;
    global $ssh;
    if (isset($ssh)) {
        $cmd = "$ssh 'DISPLAY=$display $cmd'";
    } else {
        $cmd = "DISPLAY=$display HOME=/var/www $cmd";
    }
    $result = shell_exec($cmd);
    trace("cmd=$cmd, result=$result");
    return $result;
}

function wmClose($id) {
    // Close window gracefully.
    displayCommand("wmctrl -i -c $id");
}

function wmHide($id) {
    // Hide window. This is done by moving it to desktop 1.
    displayCommand("wmctrl -i -r $id -t 1");
}

function wmShow($id) {
    // Show window on current desktop.
    displayCommand("wmctrl -i -R $id");
}

function windowListOnScreen() {
    $list = array();
    $windows = explode("\n", displayCommand('wmctrl -l'));
    foreach ($windows as $w) {
        $field = explode(' ', $w);
        $id = $field[0];
        if ($id != '') {
            array_push($list, $id);
        }
    }
    return $list;
}

// simple list with content from database

function windowList() {
    $list = array();
    global $dbcon;
    $field = "name";
    $order = "ASC";

    $windows = $dbcon->getWindowsOrderBy($field, $order);
    foreach ($windows as $w) {
// trace("w = " . serialize($w));
        $id = $w['win_id'];
// trace("id = $id");
        if ($id != '') {
            array_push($list, $id);
        }
    }
    return $list;
}

function closeAll() {
    global $dbcon;

    $windows_on_screen = windowListOnScreen();

    foreach ($windows_on_screen as $id) {
        wmClose($id);
        // trace("closeAllWindows: $id");
        if ($dbcon->getState_Window($id) != null) {
            $dbcon->deleteWindow($id);
        }
    }

    clearUploadDir();
}

function doLogout($username) {
    global $dbcon;
    if ($username == 'ALL') {
        // Terminate all user connections and reset system.
        closeAll();
        //restartVNCDaemon();
        $dbcon->resetTables();
    }
}

function clearUploadDir() {
    global $conf;
    $upload_dir = $conf['path']['upload_dir'];

    if (is_dir($upload_dir)) {
        if ($dh = opendir($upload_dir)) {
            while (($file = readdir($dh)) !== false) {
                if ($file != "." AND $file != "..") {
                    unlink("$upload_dir/$file");
                }
            }
            closedir($dh);
        }
    }
}

function setLayout($layout) {
    // Set layout of team display.

    trace("layout $layout");

    global $dbcon;
    $dbcon->exec("UPDATE setting SET value='$layout' WHERE key='layout'");

    $geom['g1x1'] = array(
                    array(0, 0, 1, 1)
                  );
    $geom['g2x1'] = array(
                    array(0, 0, 2, 1), array(1, 0, 2, 1)
                  );
    $geom['g1x2'] = array(
                    array(0, 0, 1, 2), array(0, 1, 1, 2)
                  );
    $geom['g1a2'] = array(
                    array(0, 0, 2, 1), array(1, 0, 2, 2),
                    array(1, 1, 2, 2)
                  );
    $geom['g2x2'] = array(
                    array(0, 0, 2, 2), array(1, 0, 2, 2),
                    array(0, 1, 2, 2), array(1, 1, 2, 2)
                  );

    $dim = $geom[$layout];

    // Make sure that desktop 0 is selected.
    displayCommand('wmctrl -s 0');

    // Get width and height of desktop.
    $desktops = displayCommand("wmctrl -d");
    // $desktop looks like this.
    // 0  * DG: 1600x900  VP: 0,0  WA: 0,27 1600x873  Arbeitsfläche 1
    $fields = preg_split("/[\n ]+/", $desktops);
    $geom = preg_split("/x/", $fields[3]);
    $screenWidth = $geom[0];
    $screenHeight = $geom[1];

    $wi = 0;
    foreach (windowList() as $id) {
        if ($wi < count($dim)) {
            // Show window, set size and position.
            $dx = $screenWidth / $dim[$wi][2];
            $dy = $screenHeight / $dim[$wi][3];
            $x = $dim[$wi][0] * $dx;
            $y = $dim[$wi][1] * $dy;
            wmShow($id);
            $ret = displayCommand("wmctrl -r $id -i -e 0,$x,$y,$dx,$dy");
        } else {
            // Hide window.
            wmHide($id);
        }
        $wi += 1;
    }
}

function activateControls($windowhex) {
    global $dbcon;
    $fhandler = $dbcon->querySingle("SELECT handler FROM window WHERE win_id=\"$windowhex\"");
    error_log("activateControls for handler $fhandler");
}

function restartVNCDaemon() {
    global $display;

    trace("+++ Restart SSVNC Daemon +++ ");

    $pinfo = shell_exec("ps -ef | grep SSVNC | nawk '{ print $2 }'");

    $pid = preg_split("/\n/", $pinfo);
    $kill = "";

    for ($i = 0; $i < count($pid) - 1; $i++) {
        $kill = shell_exec("kill " . $pid[$i]);
        trace("proc with procid " . $pid[$i] . " killed ");
    }

    $webroot = $_SERVER['DOCUMENT_ROOT'];
    $subdir = $_SERVER['PHP_SELF'];
    $path = $webroot . substr($subdir, 0, (strlen($subdir) - (strrpos($subdir, '/') + 4)));

    $startup = shell_exec("export DISPLAY=$display; cd $path; php SSVNCDaemon.php");
    // $user = shell_exec("whoami");

    // trace("Execute task as user $user");
    trace("Return value for kill command: $kill");
    // trace("Return value for startup command: $startup");

    trace("+++ SSVNC Daemon restarted +++");
}

function addNewWindow($dbcon, $new) {
    trace("addNewWindow '$new'");
    $new = unserialize($new);
    // '$new' already contains 'file', 'handler' and 'date'.
    // 'win_id', 'name' have to be defined afterwards.

    // TODO: db feld 'name' in 'section' umbenennen

    // Get new window. Wait up to 10 s for it.
    $t_total = 0;
    do {
        $window_ids_on_screen = windowListOnScreen();
        $windows_in_db = $dbcon->getWindows();

        $existing_ids = array();
        $new_window_id = '';

        if (count($windows_in_db) > 0) {
            // Add db windows to existing_ids.
            foreach ($windows_in_db as $win) {
                $existing_ids[] = $win['win_id'];
            }

            $new_window = array_diff($window_ids_on_screen, $existing_ids);
            foreach ($new_window as $win_id) {
                if($win_id != "") {
                    $new_window_id = $win_id;
                }
            }
        } else if (!empty($window_ids_on_screen)) {
            $new_window_id = $window_ids_on_screen[0];
        }
    } while (!$new_window_id && $t_total++ <= 10 && !sleep(1));
    trace("new window $new_window_id");

    // Determine last assigned monitor section.
    $max_section = $dbcon->maxSection();

    $section = $max_section + 1;
    trace("(7) maxSection: $max_section");

    // If all information is available, create window object.

    $new['id'] = $dbcon->nextID();
    $new['name'] = $section;

    if ($new['id'] <= 4) {
        $new['state'] = "active";
    } else {
        $new['state'] = "inactive";
        displayCommand('xdotool windowminimize ' . hexdec($new_window_id));
        trace("$new_window_id + " . hexdec($new_window_id));
    }

    // $new['file'] = $active_window; (?)

    // TODO: check how to insert the userid.
    // Perhaps better add to array in upload.php ?
    $new['userid'] = "all";

    $myWindow = array(
        $new['id'],
        $new_window_id,
        $new['name'],
        $new['state'],
        $new['file'],
        $new['handler'],
        $new['userid'],
        $new['date']
    );

    // Save window in database.
    $dbcon->insertWindow($myWindow);
}

function createNewWindow($dbcon, $new) {
    $w = unserialize($new);
    // '$new' already contains 'file', 'handler' and 'date'.
    // 'win_id', 'name' have to be defined afterwards.

    $handler = $w['handler'];
    // TODO: use escapeshellarg() for filename.
    $filename = $w['file'];

    $cmd = "$handler '$filename'";
    displayCommand("/usr/bin/nohup $cmd >/dev/null 2>&1 &");

    addNewWindow($dbcon, $new);
}

function processRequests() {

    global $dbcon;

    if (array_key_exists('window', $_REQUEST)) {
        // All windows related commands must start with window=.

    $windownumber = $_REQUEST['window'];
    if ($windownumber != 'vncwin') {
        // This is the normal case.
        // Special handling is needed when called with window=vncwin, see below.
        $window = $windownumber - 1;

        // TODO: $win_id und $windowname können vermutlich zusammengefasst werden.
        $win_id = $dbcon->getWindowIDBySection($windownumber);
        $windowlist = windowList();

        if (count($windowlist) == 0) {
            trace("no window found for command");
            $windowname = 0;
            $windowhex = 0;
        } else {
            $windowname = $windowlist[$window];
            $windowhex = hexdec($windowname);
        }
    }

    if (array_key_exists('key', $_REQUEST)) {
        $key = $_REQUEST['key'];
        trace("key '$key' in window '$windownumber'");
        wmShow($windowname);
            // activateControls($windowhex);
        // displayCommand("xdotool windowfocus $windowhex key $key");

        // trying mousemove and click for better vnc control
        displayCommand("xdotool mousemove --window $windowhex 100 100 " .
                       "key $key");
    }

    if (array_key_exists('keydown', $_REQUEST)) {
        // TODO: keydown is currently mapped to key because we had problems
        // with sticking keys (no keyup seen). This should be fixed by a
        // better event handling.
        $key = $_REQUEST['keydown'];
        trace("keydown '$key' in window '$windownumber'");
        wmShow($windowname);
            // activateControls($windowhex);
        // displayCommand("xdotool windowfocus $windowhex key $key");

        // trying mousemove and click for better vnc control
        displayCommand("xdotool mousemove --window $windowhex 100 100 " .
                       "key $key");
        //~ displayCommand("xdotool windowfocus $windowhex keydown $key");
    }

    if (array_key_exists('keyup', $_REQUEST)) {
        // TODO: keyup is currently ignored, see comment above.
        $key = $_REQUEST['keyup'];
        trace("keyup '$key' in window '$windownumber'");
            // activateControls($windowhex);
        //~ wmShow($windowname);
        //~ displayCommand("xdotool windowfocus $windowhex keyup $key");
    }

    if (array_key_exists('delete', $_REQUEST)) {

        $delete = str_replace(" ","\ ", addslashes($_REQUEST['delete']));
        trace("delete=$delete, close window $windownumber");

        if (file_exists($delete)) {
            trace("+++ DELETE FILE FROM WEBINTERFACE +++");
            unlink($delete);
        } else if ($delete == "VNC")  {
            trace("+++ DELETE VNC Client FROM DAEMON +++");
            // call via daemon: ?window=vncwin&delete=VNC&vncid=123
            trace("vnc delete in control");
            $win_id = $_REQUEST['vncid'];   // = hexWindow in database, but not on screen
            trace("VNC cia Daemon ... id=$win_id");

        } else if (strstr($delete, "http")) {
            trace("+++ DELETE Browserwindow +++");
        } else if (preg_match('/(^\w{3,}@\w{1,})/', $delete)) {
            trace("+++ DELETE VNC Client FROM WEBINTERFACE +++");
            // call via webinterface
            $win_id = $dbcon->querySingle("SELECT win_id FROM window WHERE file=\"$delete\" AND handler=\"vnc\"");
            trace("DELETE VNC Window with ID=$win_id FROM Database ::
                SELECT win_id FROM window WHERE file='$delete' AND handler='vnc'");
        } else {
            trace("Unhandled delete for '$delete'");
        }

        // displayCommand("xdotool windowkill $win_id");
        wmClose($win_id);
        $dbcon->deleteWindow($win_id);
    }

    if (array_key_exists('closeOrphans', $_REQUEST)) {

        // win_ids in db
        $windows_in_db = $dbcon->getWindows();
        $db_ids = array();

        if (count($windows_in_db) > 0) {
            foreach ($windows_in_db as $win) {
                array_push($db_ids, $win['win_id']);
            }
        }

        // win_ids on screen
        $screen_ids = windowListOnScreen();

        // orphaned windows
        $orphan_ids = array_diff($screen_ids, $db_ids);

        if (count($orphan_ids > 0)) {
            // close windows on screen not existing in database
            foreach ($orphan_ids as $id) {
                wmClose($id);
            }
        }

    }

    if (array_key_exists('toggle', $_REQUEST)) {
        $windowhex = hexdec($win_id);
        trace("toggle in window '$windownumber' (id=$win_id)");
        // wmShow($win_id);
        // displayCommand('xdotool windowkill ' . hexdec($windowname));

        // just change state
        $state = $dbcon->getState_Window($win_id);
        trace("Window Status: $state");
        if ($state == "active") {
            displayCommand("xdotool windowminimize $windowhex");
            $new_state = $dbcon->setState_Window($win_id, "inactive");
        } else {
            displayCommand("xdotool windowactivate $windowhex");
            $new_state = $dbcon->setState_Window($win_id, "active");
        }
    }
    } else if (array_key_exists('layout', $_REQUEST)) {
        setLayout($_REQUEST['layout']);
    } else if (array_key_exists('logout', $_REQUEST)) {
        doLogout($_REQUEST['logout']);
    } else if (array_key_exists('newVncWindow', $_REQUEST)) {
        // TODO: Better write new code for VNC window.
        addNewWindow($dbcon, urldecode($_REQUEST['newVncWindow']));
    } else if (array_key_exists('newWindow', $_REQUEST)) {
        createNewWindow($dbcon, urldecode($_REQUEST['newWindow']));
    }

if (array_key_exists('switchWindows', $_REQUEST)) {
    $before = $_REQUEST['before'];
    $after = $_REQUEST['after'];
    trace("switching $before and $after");

    $temp = "tmp";

    // exchange section
    $win_id1 = $dbcon->getWindowIDBySection($before);
    $win_id2 = $dbcon->getWindowIDBySection($after);
    trace("exchange section $win_id1 with section $win_id2");

    $update1 = $dbcon->updateWindow($win_id1, "name", $after);
    $update2 = $dbcon->updateWindow($win_id2, "name", $before);

    trace("++updating database $win_id1 name=$after");
    trace("++updating database $win_id2 name=$before");

    // TODO: Update layout automatically.
}

if (array_key_exists('openURL', $_REQUEST)) {
    $openURL = $_REQUEST['openURL'];
    trace("openURL $openURL");

    global $url;

    $dt = new DateTime();
    $date = $dt->format('Y-m-d H:i:s');

    $window = array(
        "id" => "",
        "win_id" => "",
        "name" => "",
        "state" => "",
        "file" => $openURL,
        // "handler" => "iceweasel --new-window ",
        "handler" => "/usr/bin/nohup /usr/bin/netsurf ",
        "userid" => "",
        "date" => $date
        );

      $serializedWindow = serialize($window);

      $sw = urlencode($serializedWindow);
      // Get cURL resource
      $curl = curl_init();
      // Set some options - we are passing in a useragent too here
      curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => "$url?newWindow=$sw",
        CURLOPT_USERAGENT => 'PalMA cURL Request'
        ));
        // Send the request & save response to $resp
        $resp = curl_exec($curl);
        // Close request to clear up some resources
        curl_close($curl);
}

// TODO: chef if query redundant?
if (array_key_exists('closeAll', $_REQUEST)) {
    $close = $_REQUEST['closeAll'];
    trace("close all windows $close");
    closeAll();
}

if (array_key_exists('getHandler', $_REQUEST)) {
    trace("getHandler from Section ID " . $_REQUEST['getHandler']);

    if (isset($_REQUEST['getHandler'])) {
        $section = $_REQUEST['getHandler'];
        }

    $fhandler = $dbcon->querySingle("SELECT handler FROM window WHERE name=$section");

    if (strpos($fhandler, 'eog') > -1) {
        $fhandler = 'eog';
    } else if (strpos($fhandler, 'libreoffice') > -1) {
        if (strpos($fhandler, '--calc') > -1) {
            $fhandler = 'libreoffice-calc';
        } else if (strpos($fhandler, '--impress') > -1) {
            $fhandler = 'libreoffice-impress';
        } else if (strpos($fhandler, '--writer') > -1) {
            $fhandler = 'libreoffice-writer';
        }
    } else if (strpos($fhandler, 'netsurf') > -1) {
        $fhandler = 'netsurf';
    } else if (strpos($fhandler, 'vlc') > -1) {
        $fhandler = 'vlc';
    } else if (strpos($fhandler, 'vnc') > -1) {
        $fhandler = 'vnc';
    } else if (strpos($fhandler, 'zathura') > -1) {
        $fhandler = 'zathura';
    }

    trace("returning FileHandle $fhandler");
    // TODO: no longer print but send return value
    print $fhandler;
    // Return value not accepted by Javascript xmlhttprequest
    return $fhandler;
}

if (array_key_exists('isFile', $_REQUEST)) {
    $section = $_REQUEST['isFile'];
    // trace("get section ID $section");

    $filename = $dbcon->querySingle("SELECT file FROM window WHERE name=$section");
    $file_exists = 0;
    if (file_exists($filename)) {
        $file_exists = 1;
    }

    trace("$filename - $file_exists");
    // TODO: no longer print but send return value
    print $file_exists;
    // Return value not accepted by Javascript xmlhttprequest
    return $file_exists;
}

} // processRequests

processRequests();

if ($unittest[__FILE__]) {

    // Experimental: Get function call from startx.
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
    if (isset($_GET) && count($_GET) > 0) {

        if (file_exists('palma.ini')) {
            // Get configuration from ini file.
            $conf = parse_ini_file("palma.ini", true);
            $display = $conf['display']['id'];
        } else {
            // Guess configuration from global PHP variables.
            $display = ':0';
        }

        foreach ($_GET as $key=>$value) {
            // Only defined actions allowed.
            if ($key == "doLogout") {
                doLogout($value);
            }
        }
    } else {
        // Run unit test.
        echo("<p>Running unit test</p>");
        trace("Running unit test for " . __FILE__);
        trace("Finished unit test");
    }
}
?>
