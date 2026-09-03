<?php

return [
    'pm_name' => 'Balen Shah',
    'pm_name_np' => 'बालेन्द्र शाह',
    'oath_date' => '2026-03-27',
    'plan_total_days' => 100,
    'plan_short_name' => '100-Point Reform Agenda',
    'cabinet_label' => 'Balen Government',

    'data_source' => [
        'name' => 'Pratipakchya — Independent Accountability Tracker',
        'url' => 'https://pratipakchya.com/tracker/',
    ],

    'categories' => [
        'national_unity' => ['label' => 'National Unity & Governance', 'label_np' => 'राष्ट्रिय एकता र शासन संरचना', 'color' => '#6366F1'],
        'admin_reform' => ['label' => 'Administrative Reform', 'label_np' => 'प्रशासन सुधार', 'color' => '#8B5CF6'],
        'anti_corruption' => ['label' => 'Anti-Corruption', 'label_np' => 'भ्रष्टाचार विरुद्ध', 'color' => '#DC2626'],
        'digital_governance' => ['label' => 'Digital Governance', 'label_np' => 'डिजिटल शासन', 'color' => '#0EA5E9'],
        'service_delivery' => ['label' => 'Service Delivery', 'label_np' => 'सेवा प्रवाह', 'color' => '#0891B2'],
        'procurement_project' => ['label' => 'Procurement & Projects', 'label_np' => 'खरिद र आयोजना', 'color' => '#64748B'],
        'revenue_reform' => ['label' => 'Revenue Reform', 'label_np' => 'राजस्व सुधार', 'color' => '#059669'],
        'investment_industry' => ['label' => 'Investment & Industry', 'label_np' => 'लगानी र उद्योग', 'color' => '#16A34A'],
        'education_health' => ['label' => 'Education & Health', 'label_np' => 'शिक्षा र स्वास्थ्य', 'color' => '#F59E0B'],
        'agriculture_land' => ['label' => 'Agriculture & Land', 'label_np' => 'कृषि र भूमि', 'color' => '#84CC16'],
        'energy_water' => ['label' => 'Energy & Water', 'label_np' => 'ऊर्जा र पानी', 'color' => '#0284C7'],
        'strategic_social' => ['label' => 'Strategic & Social Security', 'label_np' => 'रणनीतिक तथा सामाजिक सुरक्षा', 'color' => '#EC4899'],
        'other' => ['label' => 'Other', 'label_np' => 'अन्य', 'color' => '#6B7280'],
    ],

    'statuses' => [
        'done' => ['label' => 'Done', 'color' => '#10B981'],
        'in_progress' => ['label' => 'In Progress', 'color' => '#3B82F6'],
        'failed' => ['label' => 'Failed', 'color' => '#EF4444'],
        'not_started' => ['label' => 'Not Started', 'color' => '#9CA3AF'],
        'unclear' => ['label' => 'Unclear', 'color' => '#9CA3AF'],
    ],

    'deadline_types' => [
        'before_oath' => ['label' => 'before swearing-in', 'days' => 0],
        'immediate' => ['label' => 'immediate', 'days' => 0],
        '1d' => ['label' => '1 day', 'days' => 1],
        '5d' => ['label' => '5 days', 'days' => 5],
        '7d' => ['label' => '7 days', 'days' => 7],
        '15d' => ['label' => '15 days', 'days' => 15],
        '30d' => ['label' => '30 days', 'days' => 30],
        '45d' => ['label' => '45 days', 'days' => 45],
        '60d' => ['label' => '60 days', 'days' => 60],
        '90d' => ['label' => '90 days', 'days' => 90],
        '100d' => ['label' => '100 days', 'days' => 100],
        '180d' => ['label' => '180 days', 'days' => 180],
        '365d' => ['label' => '1 year', 'days' => 365],
        '1000d' => ['label' => 'long-term', 'days' => 1000],
        'rolling' => ['label' => 'rolling', 'days' => null],
        'unspecified' => ['label' => 'unspecified', 'days' => null],
    ],
];
