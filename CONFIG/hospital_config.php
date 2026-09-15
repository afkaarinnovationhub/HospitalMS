<?php
/**
 * Hospital Brand & Profile Configuration
 * Central configuration for hospital identity, receipts, invoices, and clinical branding.
 */

declare(strict_types=1);

if (!defined('HOSPITAL_NAME')) {
    define('HOSPITAL_NAME', 'Cibaar Specialist Hospital');
}

if (!defined('HOSPITAL_SHORT_NAME')) {
    define('HOSPITAL_SHORT_NAME', 'CIBAAR');
}

if (!defined('HOSPITAL_TAGLINE')) {
    define('HOSPITAL_TAGLINE', 'Specialist Care & Clinical Operations');
}

if (!defined('HOSPITAL_PHONE')) {
    define('HOSPITAL_PHONE', '+252 619 618662');
}

if (!defined('HOSPITAL_EMAIL')) {
    define('HOSPITAL_EMAIL', 'info@cibaarhospital.so');
}

if (!defined('HOSPITAL_ADDRESS')) {
    define('HOSPITAL_ADDRESS', 'Mogadishu, Somalia');
}

if (!defined('HOSPITAL_LOGO')) {
    define('HOSPITAL_LOGO', ''); // Optional path to image file
}

/**
 * Returns the official hospital name.
 */
function getHospitalName(): string {
    return HOSPITAL_NAME;
}

/**
 * Returns the short name / brand acronym.
 */
function getHospitalShortName(): string {
    return HOSPITAL_SHORT_NAME;
}

/**
 * Returns the official hospital phone number.
 */
function getHospitalPhone(): string {
    return HOSPITAL_PHONE;
}

/**
 * Returns the official hospital address.
 */
function getHospitalAddress(): string {
    return HOSPITAL_ADDRESS;
}
