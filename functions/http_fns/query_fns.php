<?php


// -- AUTH -- \\

// log in with a username and password
function pass_login($pdo, $name, $password)
{
    // get their ip
    $ip = get_ip();

    // error check
    if (empty($name) || !is_string($password) || $password == '') {
        throw new Exception('You must enter a name and a password.');
    }
    if (strlen($name) < 2) {
        throw new Exception('Your name must be at least 2 characters long.');
    }
    if (strlen($name) > 20) {
        throw new Exception('Your name can not be more than 20 characters long.');
    }

    // load the user row
    $user = user_select_full_by_name($pdo, $name);

    // check the password
    if (!password_verify(sha1($password), $user->pass_hash)) {
        if (password_verify(sha1($password), $user->temp_pass_hash)) {
            user_apply_temp_pass($pdo, $user->user_id);
        } else {
            throw new Exception('That username / password combination was not found.');
        }
    }

    // don't save hashes to memory
    unset($user->pass_hash);
    unset($user->temp_pass_hash);

    // check to see if they're banned
    check_if_banned($pdo, $user->user_id, $ip);

    // done
    return $user;
}


// log in with a token
function token_login($pdo, $use_cookie = true, $suppress_error = false)
{
    $rec_token = find_no_cookie('token');
    if (isset($rec_token) && !empty($rec_token)) {
        $token = $rec_token;
    } elseif ($use_cookie && isset($_COOKIE['token']) && !empty($_COOKIE['token'])) {
        $token = $_COOKIE['token'];
    }

    if (!isset($token)) {
        if ($suppress_error === false) {
            throw new Exception('Could not find a valid login token. Please log in again.');
        } else {
            return false;
        }
    }

    $token_row = token_select($pdo, $token);
    $user_id = (int) $token_row->user_id;

    $ip = get_ip();
    check_if_banned($pdo, $user_id, $ip);

    return $user_id;
}


// registers a user
function do_register_user($pdo, $name, $password, $ip, $time, $email)
{
    // user insert
    $pass_hash = to_hash($password);
    unset($password); // don't keep pass in memory
    user_insert($pdo, $name, $pass_hash, $ip, $time, $email);
    unset($pass_hash); // don't keep hash in memory

    // pr2 insert
    $user_id = name_to_id($pdo, $name);
    pr2_insert($pdo, $user_id);

    // welcome them
    message_send_welcome($pdo, $name, $user_id);
}


// -- MESSAGES -- \\

// sends a PM
function send_pm($pdo, $from_user_id, $to_user_id, $message)
{
    // call user info from the db
    $from_power = (int) user_select_power($pdo, $from_user_id);
    $to_power = (int) user_select_power($pdo, $to_user_id);
    $active_rank = (int) pr2_select_true_rank($pdo, $from_user_id);

    // let admins use the [url=][/url] tags
    if ($from_power >= 3) {
        $message = preg_replace(
            '/\[url=(.+?)\](.+?)\[\/url\]/',
            '<a href="\1" target="_blank"><u><font color="#0000FF">\2</font></u></a>',
            $message
        );
    }

    // get sender ip
    $ip = get_ip();

    // make sure the user's rank is above 3 (min rank to send PMs) and they aren't a guest
    if ($active_rank < 3) {
        throw new Exception('You need to be rank 3 or above to send private messages.');
    }
    if ($from_power <= 0) {
        $e = 'Guests can\'t use the private messaging system. To access this feature, please create your own account.';
        throw new Exception($e);
    }
    if ($to_power <= 0) {
         throw new Exception("You can't send private messages to guests.");
    }

    // check the length of their message
    if (strlen($message) > 1000) {
        $len = number_format(strlen($message));
        $e = "Could not send. The maximum message length is 1,000 characters. Your message is $len characters long.";
        throw new Exception($e);
    }

    // see if they've been ignored
    $ignored = ignored_select($pdo, $to_user_id, $from_user_id, true);
    if ($ignored) {
        $e = 'You have been ignored by this player. They won\'t receive any chat or messages from you.';
        throw new Exception($e);
    }

    // prevent flooding
    $rl_msg = 'You\'ve sent 4 messages in the past 60 seconds. Please wait a bit before sending another message.';
    rate_limit('pm-'.$from_user_id, 60, 4, $rl_msg);
    rate_limit('pm-'.$ip, 60, 4, $rl_msg);

    // add the message to the db
    message_insert($pdo, $to_user_id, $from_user_id, $message, $ip);
}


// sends a welcome message
function message_send_welcome($pdo, $name, $user_id)
{
    // compose a welcome pm
    $safe_name = htmlspecialchars($name, ENT_QUOTES);
    $blog_link = urlify('https://grahn.io', 'my blog');
    $email_link = urlify('mailto:jacob@grahn.io?subject=Questions or Comments about PR2', 'jacob@grahn.io');
    $welcome_message = "Welcome to Platform Racing 2, $safe_name!\n\n"
        ."You can read about the latest Platform Racing news on $blog_link.\n\n"
        ."If you have any questions or comments, send me an email at $email_link.\n\n"
        ."Thanks for playing, I hope you enjoy.\n\n"
        ."- Jiggmin";

    // welcome them
    message_insert($pdo, $user_id, 1, $welcome_message, '0');
}


// -- PARTS/EXP (PART_AWARDS, PR2, EPIC_UPGRADES, USER) -- \\

// award parts
function award_part($pdo, $user_id, $type, $part_id)
{
    $type = strtolower($type);
    $part_types = ['hat', 'head', 'body',' feet', 'ehat', 'ehead', 'ebody', 'efeet'];

    // sanity check: is it a valid type?
    if (!in_array($type, $part_types)) {
        throw new Exception("Invalid part type specified.");
    }

    // determine where in the array our value was found
    $is_epic = array_search($type, $part_types) >= 4 ? true : false;

    // get existing parts
    if ($is_epic === true) {
        $data = epic_upgrades_select($pdo, $user_id, true);
    } else {
        $data = pr2_select($pdo, $user_id, true);
    }
    $field = type_to_db_field($type);
    $str_array = $data !== false ? $data->{$field} : '';

    // explode on ,
    $part_array = explode(",", $str_array);
    if (in_array($part_id, $part_array)) {
        return false;
    }

    // insert part award, award part
    part_awards_insert($pdo, $user_id, $type, $part_id);

    // push to part array
    array_push($part_array, $part_id);

    // remove empty parts in part array
    foreach ($part_array as $key => $part) {
        if (empty($part)) {
            $part_array[$key] = null;
            unset($part_array[$key]);
        }
    }
    
    // join data to prepare for db update
    $new_field_str = join(",", $part_array);

    // award part
    if ($is_epic === true) {
        epic_upgrades_update_field($pdo, $user_id, $type, $new_field_str); // inserts if not present
    } else {
        pr2_update_part_array($pdo, $user_id, $type, $new_field_str);
    }
    return true;
}


/**
 * Awards a specified amount of EXP to a user.
 *
 * @param resource pdo Contains the current database connection instance.
 * @param int user_id The user ID of the EXP award recipient.
 * @param int exp_points The number of EXP points to award to the user.
 * @param boolean from_cron If the call is from cron_fns.php.
 *
 * @throws Exception if the user doesn't exist.
 * @throws Exception if any of the queries fail.
 * @throws Exception if the user is online.
 * @return boolean
 */
function award_exp($pdo, int $user_id, int $exp_points, bool $from_cron = false, bool $from_spec_parts = false)
{
    // sanity: awarding anything?
    if ($exp_points <= 0) {
        throw new Exception('Invalid number of EXP points to award.');
    }

    // sanity check: does the user exist?
    $user = user_select($pdo, $user_id, true);
    if ($user === false) {
        throw new Exception('This user does not exist.');
    }

    // is the user online?
    $server = user_select_server_id($pdo, $user_id, true);
    $online = $server > 0;
    if ($online && !$from_cron && !$from_spec_parts) {
        part_awards_insert($pdo, $user_id, 'exp', $exp_points);
        return true;
    } elseif ($online && ($from_cron || $from_spec_parts)) {
        throw new Exception('The user is online. We\'ll try this again later.');
    } else {
        $data = pr2_select_rank_progress($pdo, $user_id);
        $exp_needed = exp_required_for_ranking($data->rank + 1);

        // handle ranking
        $award_exp = $exp_points;
        while ($data->exp + $exp_points > $exp_needed) {
            $exp_needed = exp_required_for_ranking($data->rank + 1);
            $exp_remaining = $exp_needed - $data->exp;

            // if this will make the user's exp negative, break
            if ($exp_points - $exp_remaining < 0) {
                break;
            }

            // rank up
            $exp_points -= $exp_remaining;
            $data->rank++;
            $data->exp = 0;
        }

        $data->exp += $exp_points; // add remaining exp to the new value
        $data->rank -= $data->tokens; // remove tokens from base rank update

        pr2_update_rank($pdo, $user_id, $data->rank, $data->exp);
        part_awards_delete($pdo, $user_id, 'exp', $award_exp);

        if ($from_spec_parts) {
            return $data;
        }
    }
}


/**
 * Awards prizes to a user for various reasons on login.
 *
 * @param object stats Contains the user's current stats.
 * @param int group The value of the user's group (e.g. 1 = member, 2 = moderator, 3 = admin).
 * @param object prizes Contains any pending prizes for the user.
 *
 * @throws Exception if the part_awards_delete query fails.
 * @return object
 */
function award_special_parts($stats, $group, $prizes)
{
    global $user_id, $hat_array, $head_array, $body_array, $feet_array, $epic_upgrades;

    // get current date for holiday parts check
    $date = date('F j');

    // heart set (valentine)
    if ($date === 'February 13' || $date === 'February 14') {
        $stats->head = add_item($head_array, 38) ? 38 : $stats->head;
        $stats->body = add_item($body_array, 38) ? 38 : $stats->body;
        $stats->feet = add_item($feet_array, 38) ? 38 : $stats->feet;
    }

    // bunny set (easter)
    $easter = date('F j', easter_date(date('Y')));
    if ($date === $easter || date('F j', time() + 86400) === $easter) {
        $stats->head = add_item($head_array, 39) ? 39 : $stats->head;
        $stats->body = add_item($body_array, 39) ? 39 : $stats->body;
        $stats->feet = add_item($feet_array, 39) ? 39 : $stats->feet;
    }

    // jack-o-lantern head (halloween)
    if ($date === 'October 30' || $date === 'October 31') {
        $stats->head = add_item($head_array, 44) ? 44 : $stats->head;
    }

    // santa set (christmas)
    if ($date === 'December 24' || $date === 'December 25') {
        $stats->hat = add_item($hat_array, 7) ? 7 : $stats->hat;
        $stats->head = add_item($head_array, 34) ? 34 : $stats->head;
        $stats->body = add_item($body_array, 34) ? 34 : $stats->body;
        $stats->feet = add_item($feet_array, 34) ? 34 : $stats->feet;
    }

    // party hat (new year)
    if ($date === 'December 31' || $date === 'January 1') {
        $stats->hat = add_item($hat_array, 8) ? 8 : $stats->hat;
    }

    // crown for mods
    $stats->hat = $group >= 2 ? (add_item($hat_array, 6) ? 6 : $stats->hat) : $stats->hat;

    // stop if nothing else to award
    if ($prizes === false) {
        return $stats;
    }

    // contest awards
    foreach ($prizes as $award) {
        if ($award->type === 'exp') {
            global $pdo, $user_id;
            $data = award_exp($pdo, $user_id, (int) $award->part, false, true);
            $stats->exp_points = $data->exp;
            $stats->rank = $data->rank;
            continue;
        }

        $db_field = type_to_db_field($award->type);
        $epic = strpos($award->type, 'e') === 0 ? true : false;
        $base_type = $epic === true ? strtolower(substr($award->type, 1)) : $award->type;
        $part = (int) $award->part;

        // select array
        unset($arr); // this only unsets the reference, not the actual array, so as not to overwrite
        if ($epic === true) {
            $arr = explode(',', $epic_upgrades->$db_field);
        } else {
            $arr = &${$base_type . '_array'};
        }

        // check for existence in a part array before continuing
        if (in_array($part, $arr)) {
            global $pdo, $user_id;
            part_awards_delete($pdo, $user_id, $award->type, $part);
            continue;
        }

        // determine array to use and add part
        $added = add_item($arr, $part); // this should never return false if part_awards_delete works properly

        // if it succeeded and they have the base part, then switch to that part
        if ($added && array_search($part, ${$base_type . '_array'}) !== false) {
            $stats->$base_type = $part;
        }

        // if epic, reapply it to the epic_upgrades object
        if ($epic === true) {
            $epic_upgrades->$db_field = join(',', $arr);
        }
    }

    return $stats;
}


// check to see if a user has a part
function has_part($pdo, $user_id, $type, $part_id)
{
    $type = strtolower($type);
    $part_types = ['hat', 'head', 'body', 'feet', 'ehat', 'ehead', 'ebody', 'efeet'];

    // sanity check: is it a valid type?
    if (!in_array($type, $part_types)) {
        throw new Exception("Invalid part type specified.");
    }

    // determine where in the array our value was found
    $is_epic = array_search($type, $part_types) >= 4 ? true : false;

    // perform query
    $field = type_to_db_field($type);
    if ($is_epic === true) {
        $data = epic_upgrades_select($pdo, $user_id, true);
    } else {
        $data = pr2_select($pdo, $user_id, true);
        if ($data === false) {
            pr2_insert($pdo, $user_id); // insert pr2 data if not present
        }
    }

    // if pr2/epart data isn't present, they don't have this part
    if ($data === false) {
        return false;
    }

    // get data and convert to an array
    $parts_str = $data->{$field};
    $parts_arr = explode(",", $parts_str);

    // search for part ID in array
    return in_array($part_id, $parts_arr) ? true : false;
}


// -- BANS -- \\

// throw an exception if the user is banned
function check_if_banned($pdo, $user_id, $ip)
{
    $row = query_if_banned($pdo, $user_id, $ip);

    if ($row !== false) {
        $ban_id = $row->ban_id;
        $expire_time = $row->expire_time;
        $reason = htmlspecialchars($row->reason, ENT_QUOTES);

        // figure out what the best way to say this is
        $time_left = format_duration($expire_time - time());

        // tell it to the world
        $ban_link = urlify("https://pr2hub.com/bans/show_record.php?ban_id=$ban_id", 'here');
        $dispute_link = urlify("https://jiggmin2.com/forums/showthread.php?tid=110", 'dispute it');
        $output = "This account or IP address has been banned.\n".
            "Reason: $reason \n".
            "This ban will expire in $time_left. \n".
            "You can see more details about this ban $ban_link. \n\n".
            "If you feel that this ban is unjust, you can $dispute_link.";

        throw new Exception($output);
    }
}


// -- LEVEL_BACKUPS -- \\

// backs up a level
function backup_level(
    $pdo,
    $s3,
    $uid,
    $lid,
    $ver,
    $title,
    $live = 0,
    $rate = 0,
    $vote = 0,
    $note = '',
    $rank = 0,
    $song = 0,
    $plays = 0
) {
    $filename = "$lid.txt";
    $backup_filename = "$lid-v$ver.txt";
    $success = true;

    try {
        $result = $s3->copyObject('pr2levels1', $filename, 'pr2backups', $backup_filename);
        if (!$result) {
            throw new Exception('Could not save a backup of your level.');
        }

        level_backups_insert($pdo, $uid, $lid, $title, $ver, $live, $rate, $vote, $note, $rank, $song, $plays);
    } catch (Exception $e) {
        $success = false;
    }

    return $success;
}


// -- LEVELS (CAMPAIGNS, BEST_LEVELS, LEVELS, NEW_LEVELS) -- \\

// write a level list to the filesystem
function generate_level_list($pdo, $mode)
{
    $allowed = ['campaign', 'best', 'best_today', 'newest'];
    if (in_array($mode, $allowed)) {
        $levels = ("levels_select_" . $mode)($pdo);
    } else {
        throw new Exception("Invalid mode (\"$mode\").");
    }

    $dir = WWW_ROOT . "/files/lists/$mode/";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (range(0, 8) as $j) {
        $str = format_level_list(array_slice($levels, $j * 9, 9));
        $filename = $dir . ($j + 1);
        $ret = file_put_contents($filename, $str);
        if ($ret === false) {
            throw new Exception("Could not write level list to $filename.");
        }
    }
}


// -- GUILDS -- \\

// counts active guild members
function guild_count_active($pdo, $guild_id)
{
    $key = 'ga' . $guild_id;
    $active_count = apcu_exists($key) ? apcu_fetch($key) : guild_select_active_member_count($pdo, $guild_id);
    if (!apcu_exists($key)) {
        apcu_store($key, $active_count, 3600); // one hour
    }

    return $active_count;
}


// -- STAFF -- \\

// returns your account if you are a moderator
function check_moderator($pdo, $user_id = null, $check_ref = true, $min_power = 2)
{
    if ($check_ref === true) {
        require_trusted_ref('', true);
    }

    $user_id = (int) (is_null($user_id) ? token_login($pdo) : $user_id);
    $user = user_select_mod($pdo, $user_id, true);
    if (!$user || $user->power < $min_power) {
        throw new Exception('You lack the power to access this resource.');
    }

    return $user;
}


// determine if a user is a staff member and returns which groups
function is_staff($pdo, $user_id, $check_ref = true, $exception = false, $group = 2)
{
    $is_mod = $is_admin = false;

    if ($check_ref === true) {
        require_trusted_ref('', true);
    }

    if ($user_id !== false && $user_id !== 0) {
        // determine power and if staff
        $power = (int) user_select_power($pdo, $user_id, true);
        $is_mod = ($power >= 2);
        $is_admin = ($power === 3);

        // exception handler
        if ($exception === true && ($is_mod === false || ($group > 2 && $is_admin === false))) {
            throw new Exception('You lack the power to access this resource.');
        }
    }

    // tell the world
    $return = new stdClass();
    $return->mod = $is_mod;
    $return->admin = $is_admin;
    return $return;
}
