<?php
// back-end/api/auth/logout.php
// Called by: any page logout button
// Method: POST

require_once __DIR__ . '/../../includes/api_helper.php';

session_start();
session_destroy();
success([], 'Logged out successfully.');
