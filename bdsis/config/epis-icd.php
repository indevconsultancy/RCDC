<?php
/**
 * ICD-11 codes BDSIS acts on, generated from
 * "Updated ICD 11 Code_RCDC.xlsx" (sheet "Reference") on 2026-09-24.
 *
 * 'active' decides what the ePIS matcher accepts. Everything else in the
 * workbook is carried here too, inactive, so the list can be widened by
 * flipping a flag rather than retyping codes -- and so a reviewer can see
 * what was deliberately left out.
 *
 * Matching is exact on a whole code, never a substring: 1A03 must not match
 * 1A03.1 unless that code is listed in its own right.
 *
 * Workbook rows included as active: 3, 4, 9, 11, 21, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51.
 */
return array(

    /* ---- List of Notifiable Diseases on NEWARS online reporting (workbook rows 3-13) ---- */
    array('row' => 3, 'active' => true , 'disease' => 'Acute Bloody Diarrhea', 'codes' => array('1A40.Z'), 'source' => '1A40.Z'),
    array('row' => 4, 'active' => true , 'disease' => 'Acute Watery Diarrhea', 'codes' => array('1A40'), 'source' => '1A40'),
    array('row' => 5, 'active' => false, 'disease' => 'Acute Jaundice syndrome', 'codes' => array('ME10.1', 'SA01'), 'source' => 'ME10.1/SA01'),
    array('row' => 6, 'active' => false, 'disease' => 'Acute Respiratory Infection', 'codes' => array('CA07.0', 'CA45', 'CA07', 'CA07.1'), 'source' => 'CA07.0/CA45/CA07 / /CA07.1'),
    array('row' => 7, 'active' => false, 'disease' => 'Mumps', 'codes' => array('1D80'), 'source' => '1D80'),
    array('row' => 8, 'active' => false, 'disease' => 'Fever with Rash', 'codes' => array('1F03'), 'source' => '1F03 /'),
    array('row' => 9, 'active' => true , 'disease' => 'Food Borne iIlness', 'codes' => array('1A09.0', '1A10', 'XM3BZ7'), 'source' => '1A09.0 / 1A10 / XM3BZ7'),
    array('row' => 10, 'active' => false, 'disease' => 'Dengue Fever', 'codes' => array('1D2Z', '1D20', '1D21', '1D22'), 'source' => 'ID2Z/ID20/ID21/ /ID22'),
    array('row' => 11, 'active' => true , 'disease' => 'Typhoid/ Paratyphoid fever', 'codes' => array('1A07', '1A08'), 'source' => '1A07/1A08'),
    array('row' => 12, 'active' => false, 'disease' => 'Severe Acute Respiratory infection', 'codes' => array('1D65'), 'source' => '1D65'),
    array('row' => 13, 'active' => false, 'disease' => 'Rickettsioses', 'codes' => array('1C3Z'), 'source' => '1C3Z'),

    /* ---- Immediately Notifiable Diseases (workbook rows 16-31) ---- */
    array('row' => 16, 'active' => false, 'disease' => 'Anthrax (suspected)', 'codes' => array('1B97'), 'source' => '1B97'),
    array('row' => 17, 'active' => false, 'disease' => 'Acute Flaccid Paralysis (suspected)*', 'codes' => array('1C81'), 'source' => 'IC81'),
    array('row' => 18, 'active' => false, 'disease' => 'Acute Hemorrhagic Fever (suspected)', 'codes' => array('1D21'), 'source' => '1D21'),
    array('row' => 19, 'active' => false, 'disease' => 'Avian Influenza (suspected)', 'codes' => array('1E32'), 'source' => '1E32'),
    array('row' => 20, 'active' => false, 'disease' => 'Bacterial Meningitis (suspected)', 'codes' => array('1E30', '1E31'), 'source' => '1E30/1E31'),
    array('row' => 21, 'active' => true , 'disease' => 'Cholera (suspected)', 'codes' => array('1A00'), 'source' => '1A00'),
    array('row' => 22, 'active' => false, 'disease' => 'Malaria (confirmed)', 'codes' => array('1FAZ', '1F40.Z', '1F43', '1F41.Z', '1F40.0', '1F41.Y'), 'source' => '1FAZ/1F40.Z/1F43/1F41.Z/1F40.0/1F41.Y'),
    array('row' => 23, 'active' => false, 'disease' => 'Measles/Rubella (suspected)', 'codes' => array('1F03', '1F02'), 'source' => '1F03/1F02'),
    array('row' => 24, 'active' => false, 'disease' => 'Pertussis (suspected)', 'codes' => array('1C12'), 'source' => '1C12'),
    array('row' => 25, 'active' => false, 'disease' => 'CRS (suspected)*', 'codes' => array('KA62.8'), 'source' => 'KA62.8'),
    array('row' => 26, 'active' => false, 'disease' => 'Rabies (human suspected)', 'codes' => array('1C82'), 'source' => '1C82'),
    array('row' => 27, 'active' => false, 'disease' => 'Severe Dengue (suspected)', 'codes' => array('1D22'), 'source' => '1D22'),
    array('row' => 28, 'active' => false, 'disease' => 'Neonatal Tetanus (suspected)', 'codes' => array('1C13'), 'source' => '1C13 (tetanus)'),
    array('row' => 29, 'active' => false, 'disease' => 'Diphtheria (suspected)', 'codes' => array('1C17'), 'source' => '1C17'),
    array('row' => 30, 'active' => false, 'disease' => 'AES (suspected)*', 'codes' => array('1C85', '1C80', '1D00'), 'source' => '1C85 / 1C80 / 1D00'),
    array('row' => 31, 'active' => false, 'disease' => 'Mpox', 'codes' => array('1E71'), 'source' => '1E71'),

    /* ---- Enteric, Zoonotic & Vector-Borne Disease (workbook rows 34-63) ---- */
    array('row' => 34, 'active' => false, 'disease' => 'Leptospirosis (Suspected)*', 'codes' => array('1B91'), 'source' => '1B91'),
    array('row' => 35, 'active' => false, 'disease' => 'Brucellosis (Suspected)*', 'codes' => array('1B95'), 'source' => '1B95'),
    array('row' => 36, 'active' => false, 'disease' => 'Legionellosis (Suspected)*', 'codes' => array('1C19'), 'source' => '1C19'),
    array('row' => 37, 'active' => true , 'disease' => 'Campylobacter', 'codes' => array('1A06'), 'source' => '1A06'),
    array('row' => 38, 'active' => true , 'disease' => 'Typhoid fever', 'codes' => array('1A07'), 'source' => '1A07'),
    array('row' => 39, 'active' => true , 'disease' => 'Paratyphoid fever', 'codes' => array('1A08'), 'source' => '1A08'),
    array('row' => 40, 'active' => true , 'disease' => 'Adenovirus', 'codes' => array('1A20'), 'source' => '1A20'),
    array('row' => 41, 'active' => true , 'disease' => 'Astrovirus', 'codes' => array('1A21'), 'source' => '1A21'),
    array('row' => 42, 'active' => true , 'disease' => 'Norovirus', 'codes' => array('1A23'), 'source' => '1A23'),
    array('row' => 43, 'active' => true , 'disease' => 'Rotavirus', 'codes' => array('1A22'), 'source' => '1A22'),
    array('row' => 44, 'active' => true , 'disease' => 'Giardiasis', 'codes' => array('1A31'), 'source' => '1A31'),
    array('row' => 45, 'active' => true , 'disease' => 'Cryptosporidiosis', 'codes' => array('1A32'), 'source' => '1A32'),
    array('row' => 46, 'active' => true , 'disease' => 'Amoebiasis', 'codes' => array('1A36'), 'source' => '1A36'),
    array('row' => 47, 'active' => true , 'disease' => 'Shigella', 'codes' => array('1A02'), 'source' => '1A02'),
    array('row' => 48, 'active' => true , 'disease' => 'E coli', 'codes' => array('1A03'), 'source' => '1A03'),
    array('row' => 49, 'active' => true , 'disease' => 'Clostrididioides', 'codes' => array('1A04'), 'source' => '1A04'),
    array('row' => 50, 'active' => true , 'disease' => 'Yersinia enterocolitica', 'codes' => array('1A05'), 'source' => '1A05'),
    array('row' => 51, 'active' => true , 'disease' => 'Infections due to other salmonella', 'codes' => array('1A09'), 'source' => '1A09'),
    array('row' => 52, 'active' => false, 'disease' => 'Zika virus disease', 'codes' => array('1D48'), 'source' => '1D48'),
    array('row' => 53, 'active' => false, 'disease' => 'Helminthiases', 'codes' => array('1F9Z'), 'source' => '1F9Z'),
    array('row' => 54, 'active' => false, 'disease' => 'Leishmaniasis, unspecified', 'codes' => array('1F54.Z'), 'source' => '1F54.Z'),
    array('row' => 55, 'active' => false, 'disease' => 'Cutaneous leishmaniasis', 'codes' => array('1F54.1'), 'source' => '1F54.1'),
    array('row' => 56, 'active' => false, 'disease' => 'Visceral leishmaniasis', 'codes' => array('1F54.0'), 'source' => '1F54.0'),
    array('row' => 57, 'active' => false, 'disease' => 'Mucocutaneous leishmaniasis', 'codes' => array('1F54.2'), 'source' => '1F54.2'),
    array('row' => 58, 'active' => false, 'disease' => 'Dengue, Unspecified', 'codes' => array('1D2Z'), 'source' => '1D2Z'),
    array('row' => 59, 'active' => false, 'disease' => 'Severe dengue', 'codes' => array('1D22'), 'source' => '1D22'),
    array('row' => 60, 'active' => false, 'disease' => 'Dengue with warning signs', 'codes' => array('1D21'), 'source' => '1D21'),
    array('row' => 61, 'active' => false, 'disease' => 'Dengue without warning signs', 'codes' => array('1D20'), 'source' => '1D20'),
    array('row' => 62, 'active' => false, 'disease' => 'Scrub typhus', 'codes' => array('1C30.3'), 'source' => '1C30.3'),
    array('row' => 63, 'active' => false, 'disease' => 'Chikungunya virus disease', 'codes' => array('1D40'), 'source' => '1D40'),

    /* ---- Toxicology / Poisoning (workbook rows 68-78) ---- */
    array('row' => 68, 'active' => false, 'disease' => 'Harmful effects of drugs, medicaments or biological substances, not elsewhere classified', 'codes' => array('NE60'), 'source' => 'NE60'),
    array('row' => 69, 'active' => false, 'disease' => 'Harmful effects of or exposure to noxious substances, chiefly nonmedicinal as to source, not elsewhere classified', 'codes' => array('NE61'), 'source' => 'NE61'),
    array('row' => 70, 'active' => false, 'disease' => 'Drug toxicity associated with harm in surgical or medical care, not elsewhere classified', 'codes' => array('NE88'), 'source' => 'NE88'),
    array('row' => 71, 'active' => false, 'disease' => 'Harmful effects of other specified substance', 'codes' => array('NE6Y'), 'source' => 'NE6Y'),
    array('row' => 72, 'active' => false, 'disease' => 'Harmful effects of an unspecified substance', 'codes' => array('NE6Z'), 'source' => 'NE6Z'),
    array('row' => 73, 'active' => false, 'disease' => 'Unintentional exposure to or harmful effects of substances', 'codes' => array('PB20-PB2Z'), 'source' => 'PB20-PB2Z'),
    array('row' => 74, 'active' => false, 'disease' => 'Exposure to or harmful effects of undetermined intent of drugs, medicaments or biological substances', 'codes' => array('PH40-PH56'), 'source' => 'PH40-PH56'),
    array('row' => 75, 'active' => false, 'disease' => 'Unintentionally stung or envenomated by animal', 'codes' => array('PA78'), 'source' => 'PA78'),
    array('row' => 76, 'active' => false, 'disease' => 'Intentional self-harm by being stung or envenomated by animal', 'codes' => array('PC47'), 'source' => 'PC47'),
    array('row' => 77, 'active' => false, 'disease' => 'Other specified injury, poisoning or certain other consequences of external causes', 'codes' => array('NF2Y'), 'source' => 'NF2Y'),
    array('row' => 78, 'active' => false, 'disease' => 'Unspecified injury, poisoning or certain other consequences of external causes', 'codes' => array('NF2Z'), 'source' => 'NF2Z'),

    /* ---- IRDL (respiratory) (workbook rows 82-97) ---- */
    array('row' => 82, 'active' => false, 'disease' => 'Diseases of the respiratory system, unspecified', 'codes' => array('CB7Z'), 'source' => 'CB7Z'),
    array('row' => 83, 'active' => false, 'disease' => 'Other specified upper respiratory tract disorders', 'codes' => array('CA0Y'), 'source' => 'CA0Y'),
    array('row' => 84, 'active' => false, 'disease' => 'Other specified diseases of the respiratory system', 'codes' => array('CB40.Y'), 'source' => 'CB40.Y'),
    array('row' => 85, 'active' => false, 'disease' => 'Acute upper respiratory infection, site unspecified', 'codes' => array('CA07.0'), 'source' => 'CA07.0'),
    array('row' => 86, 'active' => false, 'disease' => 'Certain diseases of the respiratory system', 'codes' => array('CB40'), 'source' => 'CB40'),
    array('row' => 87, 'active' => false, 'disease' => 'Lower respiratory tract disease, unspecified', 'codes' => array('CA2Z'), 'source' => 'CA2Z'),
    array('row' => 88, 'active' => false, 'disease' => 'Other specified lower respiratory tract disease', 'codes' => array('CA2Y'), 'source' => 'CA2Y'),
    array('row' => 89, 'active' => false, 'disease' => 'Human respiratory syncytial virus', 'codes' => array('XN275'), 'source' => 'XN275'),
    array('row' => 90, 'active' => false, 'disease' => 'Novel Coronavirus', 'codes' => array('RA01'), 'source' => 'RA01'),
    array('row' => 91, 'active' => false, 'disease' => 'Severe acute respiratory syndrome', 'codes' => array('1D65'), 'source' => '1D65'),
    array('row' => 92, 'active' => false, 'disease' => 'MERS-CoV', 'codes' => array('XN3BD'), 'source' => 'XN3BD'),
    array('row' => 93, 'active' => false, 'disease' => 'Coronavirus infection, unspecified site', 'codes' => array('XD9S44'), 'source' => 'XD9S44'),
    array('row' => 94, 'active' => false, 'disease' => 'Human respiratory syncytial virus', 'codes' => array('XN275'), 'source' => 'XN275'),
    array('row' => 95, 'active' => false, 'disease' => 'Pneumonia due to Respiratory syncytial virus', 'codes' => array('CA40.11'), 'source' => 'CA40.11'),
    array('row' => 96, 'active' => false, 'disease' => 'Acute bronchitis due to Respiratory syncytial virus', 'codes' => array('CA42.2'), 'source' => 'CA42.2'),
    array('row' => 97, 'active' => false, 'disease' => 'Acute bronchiolitis due to respiratory syncytial virus', 'codes' => array('CA41.0'), 'source' => 'CA41.0'),
);
