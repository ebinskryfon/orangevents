<?php
/**
 * Global Utility Functions
 */

/**
 * Format currency to match Indian Rupee display (e.g. Rs. 15,000)
 * 
 * @param float $number
 * @param bool $include_symbol
 * @return string
 */
function format_price($number, $include_symbol = true) {
    // Format number to local layout (e.g. 15000 -> 15,000)
    $formatted = number_format((float)$number, 0, '.', ',');
    
    if ($include_symbol) {
        return "Rs. " . $formatted;
    }
    return $formatted;
}

/**
 * Format Date to readable format (e.g. 16/09/2026)
 * 
 * @param string $date_str (YYYY-MM-DD)
 * @return string
 */
function format_date($date_str) {
    if (empty($date_str)) return '';
    return date('d/m/Y', strtotime($date_str));
}

/**
 * Format Time to 12-hour format (e.g. 10:00 AM)
 * 
 * @param string $time_str
 * @return string
 */
function format_time($time_str) {
    if (empty($time_str)) return '';
    return date('h:i A', strtotime($time_str));
}

/**
 * Helper to escape output for safe HTML rendering
 * 
 * @param string $str
 * @return string
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
