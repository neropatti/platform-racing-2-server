<?php


/* vault-related process functions are in vault_fns.php */


// activate a happy hour
function process_activate_happy_hour($socket)
{
    if ($socket->process === true) {
        output('Activating the happiest of hours...');
        if (\pr2\multi\HappyHour::isActive() === false) {
            \pr2\multi\HappyHour::activate();
            output('Happy hour activated!');
            $socket->write('Happy hour activated!');
        } else {
            $time = format_duration(\pr2\multi\HappyHour::timeLeft());
            output("It seems there's already an active Happy Hour. It will expire in $time.");
            $socket->write("It seems there's already an active Happy Hour. It will expire in $time.");
        }
    }
}


// check server status
function process_check_status($socket)
{
    if ($socket->process === true) {
        $socket->write('This server is a-okay!');
    }
}


// disconnect player
function process_disconnect_player($socket, $data)
{
    if ($socket->process === true) {
        $obj = json_decode($data);
        $user_id = $obj->user_id;
        $message = @$obj->message;

        $player = id_to_player($user_id, false);
        if (isset($player)) {
            if (!empty($message)) {
                $player->write("message`$message");
            }
            $player->remove();
        }
    }
}


// award a part to a player on the server
function process_gain_part($socket, $data)
{
    if ($socket->process === true) {
        $obj = json_decode($data);
        $user_id = (int) $obj->user_id;
        $type = $obj->type;
        $id = (int) $obj->part_id;

        $player = id_to_player($user_id, false);
        if (isset($player)) {
            $ret = $player->gainPart($type, $id, true);
            if ($ret === true) {
                if ($player->hasPart(substr($type, 1), $id)) {
                    $player->sendCustomizeInfo(); // only notify if they own the base part
                    $player->write('message`You won something! Check your account!!!');
                }
                $socket->write('They were nice! The part was awarded.');
                return;
            } elseif ($ret === false) {
                $socket->write('Error: The player already has this part.');
            }
        } else {
            $socket->write('Error: This player is not online. Could not award part. :(');
        }
    }
}


// message a player on the server
function process_message_player($socket, $data)
{
    global $server_name;
    
    if ($socket->process === true) {
        $obj = json_decode($data);
        $user_id = $obj->user_id;
        $message = $obj->message;

        $player = id_to_player($user_id, false);
        if (isset($player)) {
            $player->write('message`' . $message);
        }
        $socket->write("Message sent to player on $server_name.");
    }
}


// set the campaign
function process_set_campaign($socket, $data)
{
    if ($socket->process === true) {
        $obj = json_decode($data);
        set_campaign((object) $obj->campaign);
        $socket->write('Campaign updated.');
    }
}


// clear player's daily exp levels
function process_start_new_day($socket)
{
    if ($socket->process === true) {
        global $player_array, $pdo;

        // log a new day
        output('New day! It is now ' . date('r') . '.');

        // reset today's exp
        foreach ($player_array as $player) {
            $player->start_exp_today = $player->exp_today = 0;
        }

        // renew database connection (prevent crashes due to query limit per connection)
        $pdo = null;
        $pdo = pdo_connect();
        output('Renewed database connection for the new day.');

        // tell calling script
        $socket->write('Another day, another destiny!');
    }
}


// run update cycle
function process_update_cycle($socket, $data)
{
    if ($socket->process === true) {
        $obj = json_decode($data);
        place_artifact($obj->artifact);
        pm_notify($obj->recent_pms);
        apply_bans($obj->recent_bans);

        $ret = new stdClass();
        $ret->plays = drain_plays();
        $ret->gp = \pr2\multi\GuildPoints::drain();
        $ret->population = get_population();
        $ret->status = get_status();
        $ret->happy_hour = (int) \pr2\multi\HappyHour::timeLeft();

        $socket->write(json_encode($ret));
    }
}


// creates a player from a successful login
function process_register_login($server_socket, $data)
{
    if ($server_socket->process == true) {
        global $login_array, $player_array, $guild_id, $guild_owner;

        $login_obj = json_decode($data);
        $login_id = (int) $login_obj->login->login_id;
        $group = (int) $login_obj->user->power;
        $user_id = (int) $login_obj->user->user_id;
        $is_fred = $user_id === FRED;
        $ps_staff_cond = $group === 3 || ($group === 2 && ($guild_id === 205 || $guild_id === 183));

        $socket = @$login_array[$login_id];
        unset($login_array[$login_id]);

        if (isset($socket)) {
            if (!$server_socket->process) {
                $socket->write('message`Error: Login verification failed.');
                $socket->close();
                $socket->onDisconnect();
            } elseif ($login_obj->login->ip !== $socket->ip) {
                $socket->write('message`Error: There\'s an IP mismatch. Check your network settings.');
                $socket->close();
                $socket->onDisconnect();
            } elseif ($guild_id !== 0 && $guild_id !== (int) $login_obj->user->guild && !$ps_staff_cond && !$is_fred) {
                $socket->write('message`Error: You are not a member of this guild.');
                $socket->close();
                $socket->onDisconnect();
            } elseif (isset($player_array[$user_id])) {
                if ($group > 0) {
                    $existing_player = $player_array[$user_id];
                    $existing_player->write('message`You were disconnected because you logged in somewhere else.');
                    $existing_player->remove();
                    $dc_msg = 'Your account was already running on this server. '
                        .'It has been logged out to save your data. Please log in again.';
                    $socket->write("message`$dc_msg");
                } else {
                    $dc_msg = 'This guest account is already online on this server. '
                        .'Please try again later, or create your own account.';
                    $socket->write("message`$dc_msg");
                }
                $socket->close();
                $socket->onDisconnect();
            } elseif (\pr2\multi\ServerBans::isBanned($login_obj->user->name)) {
                $socket->write('message`Error: You have been kicked from this server for 30 minutes.');
                $socket->close();
                $socket->onDisconnect();
            } else {
                $player = new \pr2\multi\Player($socket, $login_obj);
                $socket->player = $player;
                if ((int) $player->user_id === $guild_owner) {
                    $player->becomeServerOwner();
                } elseif ($player->group <= 0) {
                    $player->becomeGuest();
                }

                $socket->write("loginSuccessful`$group`$player->name");
                $socket->write("setRank`$player->active_rank");
                $socket->write('ping`' . time());
            }
        }
    }
}


// server shutdown
function process_shut_down($socket)
{
    if ($socket->process === true) {
        output('Received shutdown command. Initializing shutdown...');
        $socket->write('The shutdown was successful.');
        shutdown_server($socket);
    }
}
