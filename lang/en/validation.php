<?php

declare(strict_types=1);

/*
 * Validation messages — the file whose ABSENCE was a production defect.
 *
 * The app's locale and fallback are both ckb, and no locale shipped a
 * validation.php at all, so every builtin rule failure rendered as its raw
 * key: an admin filling the first project form was told `validation.required`
 * and `validation.regex`, verbatim. Laravel's bundled English messages only
 * rescue requests that RESOLVE to en — with a ckb fallback nothing ever did.
 *
 * The three locales must carry the SAME key set: a key present in one and
 * missing in another reintroduces the raw-key leak for that locale only.
 * tests/Feature/ValidationLocalizationTest.php pins that parity, and pins
 * that every rule the admin/public FormRequests actually use has a message.
 */
return [
    'accepted' => 'The :attribute field must be accepted.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'array' => 'The :attribute field must be an array.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'email' => 'The :attribute field must be a valid email address.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'not_in' => 'The selected :attribute is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'present' => 'The :attribute field must be present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'url' => 'The :attribute field must be a valid URL.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    'custom' => [],

    /*
     * Human names for the fields people actually see. Without these the
     * message says "The name_ckb field is required." — better than a raw
     * key, still machine language.
     */
    'attributes' => [
        'name_ckb' => 'name (Kurdish)',
        'name_ar' => 'name (Arabic)',
        'name_en' => 'name (English)',
        'slug' => 'URL slug',
        'project_type' => 'project type',
        'construction_status' => 'construction status',
        'delivery_status' => 'delivery status',
        'completion_percent' => 'completion percentage',
        'developer_id' => 'developer',
        'company_id' => 'company',
        'area_id' => 'area',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'boundary_wkt' => 'boundary (WKT)',
        'launch_date' => 'launch date',
        'expected_delivery' => 'expected delivery',
        'actual_delivery' => 'actual delivery',
        'land_area_sqm' => 'land area (m²)',
        'building_count' => 'building count',
        'unit_count' => 'unit count',
        'source' => 'source',
        'official_url' => 'official website',
        'confidence' => 'confidence',
        'website' => 'website',
        'email' => 'email address',
        'password' => 'password',
        'phone' => 'phone number',
        'legal_name' => 'legal name',
        'brand_name' => 'brand name',
        'telegram_username' => 'Telegram username',
        'license_number' => 'license number',
        'place_category_id' => 'category',
        'operational_status' => 'operational status',
        'price_from' => 'price from',
        'price_to' => 'price to',
        'currency' => 'currency',
        'price_type' => 'price type',
        'acting_company_id' => 'acting company',
        'association_role' => 'association role',
        'file' => 'file',
        'code' => 'code',
        'type' => 'type',
        'parent_id' => 'parent area',
    ],
];
