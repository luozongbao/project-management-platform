<?php
/**
 * Configuration Template File
 * This file will be created during installation
 */

// Database Configuration
const DB_HOST = 'localhost';
const DB_NAME = 'project_management';
const DB_USER = 'your_db_user';
const DB_PASS = 'your_db_password';

// SMTP Configuration for PHPMailer
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_PROTOCOL = 'tls';
const SMTP_USER = 'your_email@gmail.com';
const SMTP_PASS = 'your_email_password';

// Application Configuration
const APP_NAME = 'Project Management System';
const APP_VERSION = 'v1.7.2.3';
const APP_URL = 'http://localhost';
const TIMEZONE = 'UTC';

// Security
const SESSION_LIFETIME = 3600; // 1 hour
const PASSWORD_RESET_EXPIRY = 3600; // 1 hour
const ENCRYPTION_KEY = 'your-32-character-encryption-key-here';

// Login / registration brute-force protection (issue-019)
const LOGIN_FAILURE_LIMIT = 5;       // Per (IP, username) failed attempts
const LOGIN_FAILURE_WINDOW = 900;    // 15 min
const LOGIN_IP_LIMIT = 30;           // Per-IP failed attempts across all usernames
const LOGIN_IP_WINDOW = 900;         // 15 min

?>