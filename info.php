<?php
if (function_exists('sodium_crypto_secretbox')) {
    echo "Sodium está habilitado";
} else {
    echo "Sodium NO está habilitado";
}