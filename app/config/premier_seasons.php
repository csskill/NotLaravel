<?php

/**
 * CS2 Premier Season Configuration
 * 
 * Tracks Premier competitive seasons with start/end dates
 * Used to determine which season a match belongs to when processing ratings
 */

return [
    'seasons' => [
        [
            'season' => 1,
            'start' => '2023-09-27',  // CS2 Premier launch
            'end' => '2024-04-01',
            'name' => 'Season 1'
        ],
        [
            'season' => 2,
            'start' => '2024-04-01',
            'end' => '2024-09-01',
            'name' => 'Season 2'
        ],
        [
            'season' => 3,
            'start' => '2024-09-01',
            'end' => '2026-01-22',
            'name' => 'Season 3'
        ],
        [
            'season' => 4,
            'start' => '2026-01-22',
            'end' => null,  // Current season - null means ongoing
            'name' => 'Season 4'
        ]
    ],

    /**
     * Premier Rating Color Tiers
     * CS2 uses different colors for different rating ranges
     * Based on the official CS2 Premier rank system from TradeIt
     */
    'rating_colors' => [
        ['min' => 30000, 'color' => '#FFD700', 'name' => 'Gold'],           // Gold (30,000+)
        ['min' => 25000, 'color' => '#FF4444', 'name' => 'Red'],            // Red (25,000 - 29,999)
        ['min' => 20000, 'color' => '#FF1493', 'name' => 'Pink'],           // Pink (20,000 - 24,999)
        ['min' => 15000, 'color' => '#9B59B6', 'name' => 'Purple'],         // Purple (15,000 - 19,999)
        ['min' => 10000, 'color' => '#5B8DEE', 'name' => 'Blue'],           // Blue (10,000 - 14,999)
        ['min' => 5000,  'color' => '#87CEEB', 'name' => 'Light Blue'],     // Light Blue (5,000 - 9,999)
        ['min' => 0,     'color' => '#808080', 'name' => 'Gray'],           // Gray (0 - 4,999)
    ],

    /**
     * Get the color for a Premier rating
     * 
     * @param int $rating
     * @return array ['color' => string, 'name' => string]
     */
    'getRatingColor' => function (int $rating): array {
        $config = include __DIR__ . '/premier_seasons.php';

        foreach ($config['rating_colors'] as $tier) {
            if ($rating >= $tier['min']) {
                return ['color' => $tier['color'], 'name' => $tier['name']];
            }
        }

        return ['color' => '#4b69ff', 'name' => 'Calibrating'];
    },

    /**
     * Get the season number for a given date
     * 
     * @param DateTime $date
     * @return int|null Season number or null if no season active
     */
    'getSeasonForDate' => function (DateTime $date): ?int {
        $config = include __DIR__ . '/premier_seasons.php';

        foreach ($config['seasons'] as $season) {
            $start = new DateTime($season['start']);
            $end = $season['end'] ? new DateTime($season['end']) : null;

            if ($date >= $start && ($end === null || $date < $end)) {
                return $season['season'];
            }
        }

        return null;
    }
];
