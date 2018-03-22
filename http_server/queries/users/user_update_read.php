<?php

function user_update_read($pdo, $read_user_id, $message_id)
{
    $stmt = $pdo->prepare('
        UPDATE users
        SET read_message_id = :read_message_id
        WHERE user_id = :user_id
        AND read_message_id < :read_message_id
    ');
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':read_message_id', $read_message_id, PDO::PARAM_INT);

    $result = $stmt->execute();
    if ($result === false) {
        throw new Exception('Error updating user status');
    }

    return $result;
}
