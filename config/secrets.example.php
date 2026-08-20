<?php

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USERNAME = 'youremailaddress@gmail.com';
const SMTP_PASSWORD = 'your apps pass word';
const SMTP_FROM_EMAIL = 'support@yourdomain.com';
const SMTP_FROM_NAME = 'Your Company';
const SMTP_USE_AUTH = true;
const SMTP_USE_TLS = true;

define('PTERO_DB_HOST', '127.0.0.1');
define('PTERO_DB_NAME', 'pterodactyl');
define('PTERO_DB_USER', 'pterodactyl');
define('PTERO_DB_PASS', 'pterodactyl');

define('MAIN_DB_HOST', '127.0.0.1');
define('MAIN_DB_NAME', 'websitedatabase');
define('MAIN_DB_USER', 'websiteuser');
define('MAIN_DB_PASS', 'websitepassword');

/*
This section is for if you're running a local testing environment
*/
// Pterodactyl Database Info
define('PTERO_DB_HOST_L', 'panel.ip.0.0');
define('PTERO_DB_NAME_L', 'pterodactyl');
define('PTERO_DB_USER_L', 'pterodactyl');
define('PTERO_DB_PASS_L', 'pterodactyl');

// Website Database Info
define('MAIN_DB_HOST_L', 'website.ip.0.0');
define('MAIN_DB_NAME_L', 'websitedatabase');
define('MAIN_DB_USER_L', 'websiteuser');
define('MAIN_DB_PASS_L', 'websitepassword');

/*
End local testing database stuff
*/

define('PTERO_API_KEY', 'pterodactyl_general_api_key');

define('PTERO_CLIENT_API_KEY', 'pterodactyl_client_api_key');

define('PTERO_REGISTRATION_API_KEY', 'pterodactyl_registration_api_key');

define('PTERO_DB_MANAGEMENT_API_KEY', 'pterodactyl_database_management_api_key');

// Pterodactyl panel APP_KEY. Required only for frontend admin database-host
// create/password updates because database host passwords are Laravel-encrypted.
define('PTERO_APP_KEY', 'base64:your_pterodactyl_app_key');