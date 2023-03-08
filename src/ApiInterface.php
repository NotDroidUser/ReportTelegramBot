<?php

namespace TuriBot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ApiInterface
{
    function Request(string $method, array $data);
}