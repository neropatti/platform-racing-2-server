<?php

require_once __DIR__ . '/../../fns/all_fns.php';
require_once __DIR__ . '/../../fns/output_fns.php';
require_once __DIR__ . '/../../queries/users/user_select_by_name.php';
require_once __DIR__ . '/../../queries/recent_logins/recent_logins_select.php';

$name = find('name', '');
$start = (int) default_get('start', 0);
$count = (int) default_get('count', 250);

// this will echo the search box when called
function output_search($name = '', $incl_br = true)
{
    echo "<form name='input' action='' method='get'>";
    echo "Username: <input type='text' name='name' value='$name'>&nbsp;";
    echo "<input type='submit' value='Search'></form>";
    if ($incl_br) {
        echo "<br><br>";
    }
}

// this will echo and control page counts when called
function output_pagination($start, $count)
{
    $next_start_num = $start + $count;
    $last_start_num = $start - $count;
    if ($last_start_num < 0) {
        $last_start_num = 0;
    }
    echo('<p>');
    if ($start > 0) {
        echo("<a href='?start=$last_start_num&count=$count'><- Last</a> |");
    } else {
        echo('<- Last |');
    }
    echo(" <a href='?start=$next_start_num&count=$count'>Next -></a></p>");
}

// admin check try block
try {
    //connect
    $pdo = pdo_connect();

    //make sure you're an admin
    $admin = check_moderator($pdo, false, 3);
} catch (Exception $e) {
    $message = $e->getMessage();
    output_header('Error');
    echo "Error: $message";
    output_footer();
    die();
}

// admin validated try block
try {
    // header
    output_header('Player Deep Logins', true, true);

    // sanity check: no name in search box
    if (is_empty($name)) {
        output_search('', false);
        output_footer();
        die();
    }
    
    // sanity check: ensure the database doesn't overload itself
    if ($count > 500) {
        $count = 500;
    }

    // if there's a name set, let's get data from the db
    $user_id = name_to_id($pdo, $name);
    $logins = recent_logins_select($pdo, $user_id, true, $start, $count);
    
    // calculate the number of results and the grammar to go with it
    $num_results = count($logins);
    if ($num_results == 1) {
        $res = 'result';
    } else {
        $res = 'results';
    }

    // safety first
    $safe_name = htmlspecialchars($name);

    // show the search form
    output_search($safe_name);
    
    if ($num_results > 0) {
        output_pagination($start, $count);
        echo '<p>---</p>';
    }
    
    // output the number of results or kill the page if none
    echo "$num_results $res logins recorded for the user \"$safe_name\".";
    if ($num_results != 0) {
        $end = $start + $count;
        echo "<br>Showing results $start-$end<br><br>";
    } else {
        output_footer();
        die();
    }

    // only gonna get here if there were results
    foreach ($logins as $row) {
        // make nice variables for our data
        $ip = htmlspecialchars($row->ip); // ip
        $country = htmlspecialchars($row->country); // country code
        $date = htmlspecialchars($row->date); // date

        // display the data
        echo "IP: $ip | Country Code: $country | Date: $date<br>";
    }
    
    // output page navigation
    echo '<p>---</p>';
    output_pagination($start, $count);

    // end it all
    output_footer();
    die();
} catch (Exception $e) {
    $message = $e->getMessage();
    output_search($safe_name);
    echo "<i>Error: $message</i>";
    output_footer();
    die();
}