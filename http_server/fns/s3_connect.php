<?php

require_once __DIR__ . '/../vend/S3.php';

function s3_connect()
{
    global $S3_SECRET, $S3_PASS;
    $s3 = new S3($S3_SECRET, $S3_PASS);
    return($s3);
}
