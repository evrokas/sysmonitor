<?php
// Generates an API token. Give the token to the dashboard; put the hash in config.php 'api_tokens'.
$t = bin2hex(random_bytes(24));
echo "token (give to the dashboard, keep secret): $t\n";
echo "config.php entry for 'api_tokens':          '" . hash('sha256', $t) . "',\n";
