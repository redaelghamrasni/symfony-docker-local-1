<?php

/**
 * Built-in default sub-national regions, by country (ISO 3166-1 alpha-2).
 *
 * This is the shop's OWN reference data — public-domain ISO 3166-2 subdivisions
 * baked into the app, with no runtime dependency on any external service or
 * library, so it works in every country with no geographical restriction.
 *
 * It is the DEFAULT layer: a market not listed here, or a listed one a merchant
 * wants to change, is handled by the admin-managed `market_region` table, which
 * overrides these per country (see RegionCatalog). Adding a market's regions is
 * therefore either a data edit in the admin (no deploy) or a new entry here
 * (ships for everyone) — never application logic.
 *
 * Shape: [ 'CC' => [ 'CODE' => 'English name', ... ], ... ]
 * Codes are stable keys stored on the order and shared with tax rates.
 *
 * @return array<string, array<string, string>>
 */

return [
    // ── North America ──────────────────────────────────────────────────────
    'CA' => [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba',
        'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario',
        'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ],
    'US' => [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
    ],

    // ── Europe ─────────────────────────────────────────────────────────────
    'GB' => [
        'ENG' => 'England', 'SCT' => 'Scotland', 'WLS' => 'Wales', 'NIR' => 'Northern Ireland',
    ],
    'FR' => [
        'ARA' => 'Auvergne-Rhône-Alpes', 'BFC' => 'Bourgogne-Franche-Comté', 'BRE' => 'Bretagne',
        'CVL' => 'Centre-Val de Loire', 'COR' => 'Corse', 'GES' => 'Grand Est',
        'HDF' => 'Hauts-de-France', 'IDF' => 'Île-de-France', 'NOR' => 'Normandie',
        'NAQ' => 'Nouvelle-Aquitaine', 'OCC' => 'Occitanie', 'PDL' => 'Pays de la Loire',
        'PAC' => "Provence-Alpes-Côte d'Azur",
    ],
    'DE' => [
        'BW' => 'Baden-Württemberg', 'BY' => 'Bavaria', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
        'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hesse', 'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Lower Saxony', 'NW' => 'North Rhine-Westphalia', 'RP' => 'Rhineland-Palatinate',
        'SL' => 'Saarland', 'SN' => 'Saxony', 'ST' => 'Saxony-Anhalt', 'SH' => 'Schleswig-Holstein',
        'TH' => 'Thuringia',
    ],
    'ES' => [
        'AN' => 'Andalusia', 'AR' => 'Aragon', 'AS' => 'Asturias', 'CB' => 'Cantabria',
        'CL' => 'Castile and León', 'CM' => 'Castilla-La Mancha', 'CN' => 'Canary Islands',
        'CT' => 'Catalonia', 'EX' => 'Extremadura', 'GA' => 'Galicia', 'IB' => 'Balearic Islands',
        'MC' => 'Murcia', 'MD' => 'Madrid', 'NC' => 'Navarre', 'PV' => 'Basque Country',
        'RI' => 'La Rioja', 'VC' => 'Valencian Community',
    ],

    // ── Arabia (Gulf) ──────────────────────────────────────────────────────
    'SA' => [
        '01' => 'Riyadh', '02' => 'Makkah', '03' => 'Madinah', '04' => 'Eastern Province',
        '05' => 'Al-Qassim', '06' => "Ha'il", '07' => 'Tabuk', '08' => 'Northern Borders',
        '09' => 'Jazan', '10' => 'Najran', '11' => 'Al Bahah', '12' => 'Al Jawf', '14' => "'Asir",
    ],
    'AE' => [
        'AZ' => 'Abu Dhabi', 'AJ' => 'Ajman', 'DU' => 'Dubai', 'FU' => 'Fujairah',
        'RK' => 'Ras Al Khaimah', 'SH' => 'Sharjah', 'UQ' => 'Umm Al Quwain',
    ],
    'QA' => [
        'DA' => 'Doha', 'RA' => 'Al Rayyan', 'WA' => 'Al Wakrah', 'KH' => 'Al Khor',
        'MS' => 'Al-Shahaniya', 'ZA' => 'Al Daayen', 'SH' => 'Al Shamal', 'US' => 'Umm Salal',
    ],

    // ── Central Asia ───────────────────────────────────────────────────────
    'KZ' => [
        'AST' => 'Astana', 'ALA' => 'Almaty (city)', 'SHY' => 'Shymkent (city)',
        'AKM' => 'Akmola', 'AKT' => 'Aktobe', 'ALM' => 'Almaty Region', 'ATY' => 'Atyrau',
        'VOS' => 'East Kazakhstan', 'ZHA' => 'Jambyl', 'ZAP' => 'West Kazakhstan',
        'KAR' => 'Karaganda', 'KUS' => 'Kostanay', 'KZY' => 'Kyzylorda', 'MAN' => 'Mangystau',
        'PAV' => 'Pavlodar', 'SEV' => 'North Kazakhstan', 'YUZ' => 'Turkistan',
    ],
    'UZ' => [
        'AN' => 'Andijan', 'BU' => 'Bukhara', 'FA' => 'Fergana', 'JI' => 'Jizzakh',
        'NG' => 'Namangan', 'NW' => 'Navoiy', 'QA' => 'Qashqadaryo', 'QR' => 'Karakalpakstan',
        'SA' => 'Samarqand', 'SI' => 'Sirdaryo', 'SU' => 'Surxondaryo', 'TK' => 'Tashkent (city)',
        'TO' => 'Tashkent Region', 'XO' => 'Xorazm',
    ],

    // ── Western Asia ───────────────────────────────────────────────────────
    'JO' => [
        'AM' => 'Amman', 'AJ' => 'Ajloun', 'AQ' => 'Aqaba', 'AT' => 'Tafilah',
        'AZ' => 'Zarqa', 'BA' => 'Balqa', 'IR' => 'Irbid', 'JA' => 'Jerash',
        'KA' => 'Karak', 'MA' => 'Mafraq', 'MD' => 'Madaba', 'MN' => "Ma'an",
    ],
    'TR' => [
        '01' => 'Adana', '02' => 'Adıyaman', '03' => 'Afyonkarahisar', '04' => 'Ağrı',
        '05' => 'Amasya', '06' => 'Ankara', '07' => 'Antalya', '08' => 'Artvin',
        '09' => 'Aydın', '10' => 'Balıkesir', '11' => 'Bilecik', '12' => 'Bingöl',
        '13' => 'Bitlis', '14' => 'Bolu', '15' => 'Burdur', '16' => 'Bursa',
        '17' => 'Çanakkale', '18' => 'Çankırı', '19' => 'Çorum', '20' => 'Denizli',
        '21' => 'Diyarbakır', '22' => 'Edirne', '23' => 'Elazığ', '24' => 'Erzincan',
        '25' => 'Erzurum', '26' => 'Eskişehir', '27' => 'Gaziantep', '28' => 'Giresun',
        '29' => 'Gümüşhane', '30' => 'Hakkâri', '31' => 'Hatay', '32' => 'Isparta',
        '33' => 'Mersin', '34' => 'İstanbul', '35' => 'İzmir', '36' => 'Kars',
        '37' => 'Kastamonu', '38' => 'Kayseri', '39' => 'Kırklareli', '40' => 'Kırşehir',
        '41' => 'Kocaeli', '42' => 'Konya', '43' => 'Kütahya', '44' => 'Malatya',
        '45' => 'Manisa', '46' => 'Kahramanmaraş', '47' => 'Mardin', '48' => 'Muğla',
        '49' => 'Muş', '50' => 'Nevşehir', '51' => 'Niğde', '52' => 'Ordu',
        '53' => 'Rize', '54' => 'Sakarya', '55' => 'Samsun', '56' => 'Siirt',
        '57' => 'Sinop', '58' => 'Sivas', '59' => 'Tekirdağ', '60' => 'Tokat',
        '61' => 'Trabzon', '62' => 'Tunceli', '63' => 'Şanlıurfa', '64' => 'Uşak',
        '65' => 'Van', '66' => 'Yozgat', '67' => 'Zonguldak', '68' => 'Aksaray',
        '69' => 'Bayburt', '70' => 'Karaman', '71' => 'Kırıkkale', '72' => 'Batman',
        '73' => 'Şırnak', '74' => 'Bartın', '75' => 'Ardahan', '76' => 'Iğdır',
        '77' => 'Yalova', '78' => 'Karabük', '79' => 'Kilis', '80' => 'Osmaniye',
        '81' => 'Düzce',
    ],
];
