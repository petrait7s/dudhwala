<?php

// Bangla messages for the rules the admin forms use. Anything missing falls back to English.
return [
    'required' => ':attribute লিখতে হবে।',
    'string' => ':attribute লেখা হতে হবে।',
    'integer' => ':attribute পূর্ণসংখ্যা হতে হবে।',
    'numeric' => ':attribute সংখ্যা হতে হবে।',
    'boolean' => ':attribute হ্যাঁ বা না হতে হবে।',
    'email' => ':attribute সঠিক ইমেইল ঠিকানা হতে হবে।',
    'unique' => ':attribute আগেই ব্যবহার হয়েছে।',
    'exists' => 'বাছাই করা :attribute সঠিক নয়।',
    'in' => 'বাছাই করা :attribute সঠিক নয়।',
    'regex' => ':attribute-এর ধরন সঠিক নয়।',
    'distinct' => ':attribute একাধিকবার দেওয়া হয়েছে।',
    'array' => ':attribute তালিকা হতে হবে।',
    'date' => ':attribute সঠিক তারিখ নয়।',
    'between' => [
        'numeric' => ':attribute :min থেকে :max এর মধ্যে হতে হবে।',
    ],
    'max' => [
        'numeric' => ':attribute :max এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
        'array' => ':attribute-এ :max টির বেশি থাকতে পারবে না।',
    ],
    'min' => [
        'numeric' => ':attribute কমপক্ষে :min হতে হবে।',
        'string' => ':attribute কমপক্ষে :min অক্ষর হতে হবে।',
        'array' => ':attribute-এ কমপক্ষে :min টি থাকতে হবে।',
    ],
    'attributes' => [
        'name' => 'নাম', 'name_bn' => 'বাংলা নাম', 'name_en' => 'ইংরেজি নাম', 'email' => 'ইমেইল', 'password' => 'পাসওয়ার্ড', 'role' => 'ভূমিকা',
        'slug' => 'স্লাগ', 'code' => 'কোড', 'category_id' => 'ক্যাটাগরি', 'address_line' => 'ঠিকানা', 'phone' => 'ফোন', 'status' => 'অবস্থা',
        'label_bn' => 'বাংলা লেবেল', 'label_en' => 'ইংরেজি লেবেল', 'price' => 'দাম', 'compare_at' => 'আগের দাম', 'max_qty' => 'সর্বোচ্চ পরিমাণ',
        'sort_order' => 'ক্রম', 'title_bn' => 'বাংলা শিরোনাম', 'body_bn' => 'বাংলা লেখা', 'outer_city_status' => 'আউটার সিলেট অবস্থা',
    ],
];
