-- ============================================================================
-- migrate_039_materials.sql
--
-- Montessori material inventory + monthly condition audit (the Kreedo
-- replacement workflow). Three tables:
--
--   mm_materials         one row per physical material, grouped by location
--                        (shelf). Seeded from the school's material sheet.
--   mm_condition_checks  one condition mark per (material, month). Records
--                        the condition code, whether it needs replacing, an
--                        optional quantity + note, and WHO marked it WHEN.
--   mm_condition_media   photos / videos of a material's condition, attached
--                        to a check. Files live under uploads/material_media.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS guard
-- on the seed so re-running never duplicates the catalogue.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mm_materials (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(200) NOT NULL,
    location    VARCHAR(120) NOT NULL DEFAULT '',
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mm_loc (location, sort_order),
    KEY idx_mm_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mm_condition_checks (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id       INT UNSIGNED NOT NULL,
    period            CHAR(7)      NOT NULL,          -- 'YYYY-MM'
    condition_code    VARCHAR(20)  NOT NULL,
    needs_replacement TINYINT(1)   NOT NULL DEFAULT 0,
    replace_qty       INT UNSIGNED NOT NULL DEFAULT 0,
    notes             TEXT         NULL,
    checked_by_user_id INT UNSIGNED NULL,
    checked_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mm_check (material_id, period),
    KEY idx_mm_check_period (period, needs_replacement),
    CONSTRAINT fk_mm_check_material FOREIGN KEY (material_id) REFERENCES mm_materials(id) ON DELETE CASCADE,
    CONSTRAINT fk_mm_check_user     FOREIGN KEY (checked_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mm_condition_media (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    check_id          INT UNSIGNED NOT NULL,
    kind              ENUM('photo','video') NOT NULL DEFAULT 'photo',
    original_filename VARCHAR(255) NOT NULL,
    stored_filename   VARCHAR(255) NOT NULL,
    mime_type         VARCHAR(100) NOT NULL,
    size_bytes        INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by_user_id INT UNSIGNED NULL,
    uploaded_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mm_media_stored (stored_filename),
    KEY idx_mm_media_check (check_id),
    CONSTRAINT fk_mm_media_check FOREIGN KEY (check_id) REFERENCES mm_condition_checks(id) ON DELETE CASCADE,
    CONSTRAINT fk_mm_media_user  FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Seed the material catalogue (only when empty) -------------------------
INSERT INTO mm_materials (name, location, sort_order)
SELECT name, location, sort_order FROM (
    SELECT 'Simple Building Blocks' AS name, 'Shelf 1' AS location, 1 AS sort_order
    UNION ALL
    SELECT 'Big Button Sewing Block', 'Shelf 1', 2
    UNION ALL
    SELECT 'Name the Vegetables', 'Shelf 1', 3
    UNION ALL
    SELECT 'Car Puzzle', 'Shelf 1', 4
    UNION ALL
    SELECT 'Name the Birds', 'Shelf 1', 5
    UNION ALL
    SELECT 'Size  Variation Butterfly', 'Shelf 1', 6
    UNION ALL
    SELECT 'Ship Puzzle', 'Shelf 1', 7
    UNION ALL
    SELECT 'Rainbow Roofs', 'Shelf 1', 8
    UNION ALL
    SELECT 'Coloured Beetles', 'Shelf 1', 9
    UNION ALL
    SELECT 'Sand art', 'Shelf 1', 10
    UNION ALL
    SELECT 'Lock Box Small', 'Shelf 2', 11
    UNION ALL
    SELECT 'Opening & closing activity', 'Shelf 2', 12
    UNION ALL
    SELECT 'Chapatti Roller (Rolling Board & Pin)', 'Shelf 2', 13
    UNION ALL
    SELECT 'Sandalwood paste', 'Shelf 2', 14
    UNION ALL
    SELECT 'Clay', 'Shelf 2', 15
    UNION ALL
    SELECT 'Visual Pairing – Cloth', 'Shelf 3', 16
    UNION ALL
    SELECT 'Visual Pairing – Animals', 'Shelf 3', 17
    UNION ALL
    SELECT 'Visual Pairing - Things around the house', 'Shelf 3', 18
    UNION ALL
    SELECT 'Visual Pairing – Fruits', 'Shelf 3', 19
    UNION ALL
    SELECT 'Visual Pairing – Transport', 'Shelf 3', 20
    UNION ALL
    SELECT 'Fastening Frame: Hooks', 'Shelf 3', 21
    UNION ALL
    SELECT 'Puzzle: Parts of Body', 'Shelf 3', 22
    UNION ALL
    SELECT 'Rough and Smooth Board', 'Shelf 3', 23
    UNION ALL
    SELECT 'Touch Tablets (5 Pairs)', 'Shelf 3', 24
    UNION ALL
    SELECT 'Thread and Learn Quadrilaterals', 'Shelf 3', 25
    UNION ALL
    SELECT 'Patterns for 2D Geometric Tiles', 'Shelf 3', 26
    UNION ALL
    SELECT '2D Geometric Tiles', 'Shelf 3', 27
    UNION ALL
    SELECT 'Pair the Colours', 'Shelf 3', 28
    UNION ALL
    SELECT 'Tree Puzzle', 'Shelf 3', 29
    UNION ALL
    SELECT 'Blindfold', 'Shelf 3', 30
    UNION ALL
    SELECT 'Fastening Frame: Velcro', 'Shelf 3', 31
    UNION ALL
    SELECT 'Fastening Frame: Press Button', 'Shelf 3', 32
    UNION ALL
    SELECT 'Fastening Frame: Coat Button', 'Shelf 3', 33
    UNION ALL
    SELECT 'Breadth Stairs', 'Shelf 4', 34
    UNION ALL
    SELECT 'Ship Shape Sorter', 'Shelf 4', 35
    UNION ALL
    SELECT 'Height Stairs', 'Shelf 4', 36
    UNION ALL
    SELECT 'Sound Boxes', 'Shelf 4', 37
    UNION ALL
    SELECT 'Geometry Board', 'Shelf 4', 38
    UNION ALL
    SELECT 'Geometry Shape Cards', 'Shelf 4', 39
    UNION ALL
    SELECT 'Texture Box/Fabric box', 'Shelf 4', 40
    UNION ALL
    SELECT 'Colour Gradation Tablets', 'Shelf 4', 41
    UNION ALL
    SELECT 'Grip and Match / Stereognostic Bag', 'Shelf 4', 42
    UNION ALL
    SELECT 'Colour Viewer', 'Shelf 4', 43
    UNION ALL
    SELECT 'Colour Gradation Tablets', 'Shelf 4', 44
    UNION ALL
    SELECT 'Cylinder Block Diameter Variation', 'Shelf 4', 45
    UNION ALL
    SELECT 'Cylinder Block Height Variation', 'Shelf 4', 46
    UNION ALL
    SELECT 'Fixed Quantity Material', 'Shelf 5', 47
    UNION ALL
    SELECT 'Number Cards 1 to 10', 'Shelf 5', 48
    UNION ALL
    SELECT 'Unit Counters: Set of 55', 'Shelf 5', 49
    UNION ALL
    SELECT 'Sequencing Puzzle 1 to 20', 'Shelf 5', 50
    UNION ALL
    SELECT 'Trace the number shapes', 'Shelf 5', 51
    UNION ALL
    SELECT 'Circle Sorter', 'Shelf 5', 52
    UNION ALL
    SELECT 'Triangle Sorter', 'Shelf 5', 53
    UNION ALL
    SELECT 'Rectangle Sorter', 'Shelf 5', 54
    UNION ALL
    SELECT 'Number Cards 1 – 1000', 'Shelf 5', 55
    UNION ALL
    SELECT 'Felt Material: TH, H, T, U', 'Shelf 5', 56
    UNION ALL
    SELECT 'Quantity Box', 'Shelf 5', 57
    UNION ALL
    SELECT 'Counting Jigsaw', 'Shelf 5', 58
    UNION ALL
    SELECT 'Counting Rings: Coloured 1-10', 'Shelf 5', 59
    UNION ALL
    SELECT 'Special Names 11-19 and 10 to 90', 'Shelf 5', 60
    UNION ALL
    SELECT 'Place Value System', 'Shelf 5', 61
    UNION ALL
    SELECT 'Addition Control Base', 'Shelf 5', 62
    UNION ALL
    SELECT 'Trace the Alphabets: Print', 'Shelf 6', 63
    UNION ALL
    SELECT 'Stand for sandpaper letters', 'Shelf 6', 64
    UNION ALL
    SELECT 'Clip Pads Small Each', 'Shelf 6', 65
    UNION ALL
    SELECT 'Metal Insets/Geometric Insets', 'Shelf 6', 66
    UNION ALL
    SELECT 'Pattern Writing Maze', 'Shelf 6', 67
    UNION ALL
    SELECT 'Picture Association', 'Shelf 6', 68
    UNION ALL
    SELECT 'Public Places Puzzles', 'Shelf 6', 69
    UNION ALL
    SELECT 'Opposites', 'Shelf 6', 70
    UNION ALL
    SELECT 'Vowel cards', 'Shelf 6', 71
    UNION ALL
    SELECT 'Number cards small (1 to 3000)', 'Shelf 7', 72
    UNION ALL
    SELECT 'Number Cards Large (1 to 9000)', 'Shelf 7', 73
    UNION ALL
    SELECT 'Place Value Board', 'Shelf 7', 74
    UNION ALL
    SELECT 'Equivalent', 'Shelf 7', 75
    UNION ALL
    SELECT 'Decimal System Complete', 'Shelf 7', 76
    UNION ALL
    SELECT 'Dot Game', 'Shelf 7', 77
    UNION ALL
    SELECT 'Geometrical Solids', 'Shelf 7', 78
    UNION ALL
    SELECT 'Addition tables of 10', 'Shelf 8', 79
    UNION ALL
    SELECT 'Multiplication Tables Game', 'Shelf 8', 80
    UNION ALL
    SELECT 'Subtraction Boards with tray', 'Shelf 8', 81
    UNION ALL
    SELECT 'Division Game', 'Shelf 8', 82
    UNION ALL
    SELECT 'Equations Box', 'Shelf 8', 83
    UNION ALL
    SELECT 'Number Dominoes - To change to arithmetic', 'Shelf 8', 84
    UNION ALL
    SELECT 'Squares of Numbers', 'Shelf 8', 85
    UNION ALL
    SELECT 'Clock', 'Shelf 9', 86
    UNION ALL
    SELECT 'Tic Tac Toe', 'Shelf 9', 87
    UNION ALL
    SELECT 'Number Line', 'Shelf 9', 88
    UNION ALL
    SELECT 'Flower Stacker', 'Shelf 9', 89
    UNION ALL
    SELECT 'Venn Diagram Game', 'Shelf 9', 90
    UNION ALL
    SELECT 'Linear Measurements', 'Shelf 9', 91
    UNION ALL
    SELECT 'Number Tiling Game', 'Shelf 9', 92
    UNION ALL
    SELECT 'Bar Graph', 'Shelf 9', 93
    UNION ALL
    SELECT 'Fractions Game', 'Shelf 9', 94
    UNION ALL
    SELECT 'Place and Directions Box', 'Shelf 10', 95
    UNION ALL
    SELECT 'Phonogram Word Forming Sets', 'Shelf 10', 96
    UNION ALL
    SELECT 'Reading Lists Phonograms Green Set', 'Shelf 10', 97
    UNION ALL
    SELECT 'Community Helpers and Professionals', 'Shelf 10', 98
    UNION ALL
    SELECT 'Rhyming Words', 'Shelf 10', 99
    UNION ALL
    SELECT 'Compound Word Cards', 'Shelf 10', 100
    UNION ALL
    SELECT 'Sentence Boxes', 'Shelf 10', 101
    UNION ALL
    SELECT 'Sentence Structure Game', 'Shelf 10', 102
    UNION ALL
    SELECT 'Hindi Movable Alphabet Box', 'Shelf 10', 103
    UNION ALL
    SELECT 'Lifecycle of Butterfly', 'Shelf 11', 104
    UNION ALL
    SELECT 'Lifecycle of Hen', 'Shelf 11', 105
    UNION ALL
    SELECT 'Water World Dominoes', 'Shelf 11', 106
    UNION ALL
    SELECT 'Food Chain (Set of 3 boards with control card)', 'Shelf 11', 107
    UNION ALL
    SELECT 'Flags Pairing', 'Shelf 11', 108
    UNION ALL
    SELECT 'Timeline Eras of the World', 'Shelf 11', 109
    UNION ALL
    SELECT 'Costumes of the world', 'Shelf 11', 110
    UNION ALL
    SELECT 'Bar magnets', 'Shelf 11', 111
    UNION ALL
    SELECT 'Globe (Continent)', 'Shelf 11', 112
    UNION ALL
    SELECT 'Timeline of humans', 'Shelf 11', 113
    UNION ALL
    SELECT 'Length Stairs', 'Materials kept on the floor', 114
    UNION ALL
    SELECT 'Pink Tower', 'Materials kept on the floor', 115
    UNION ALL
    SELECT 'Colour Gradation wheel', 'Materials kept on the floor', 116
    UNION ALL
    SELECT 'Solar System', 'Materials kept on the floor', 117
    UNION ALL
    SELECT 'Wooden Worksheet: Quantity Sequencing', 'Shelf for works sheets', 118
    UNION ALL
    SELECT 'Wooden Worksheet: Number Sequencing', 'Shelf for works sheets', 119
    UNION ALL
    SELECT 'Wooden Worksheet: Classification grouping 1', 'Shelf for works sheets', 120
    UNION ALL
    SELECT 'Wooden Worksheet: Classification grouping 2', 'Shelf for works sheets', 121
    UNION ALL
    SELECT 'Wooden Worksheet: Seriation', 'Shelf for works sheets', 122
    UNION ALL
    SELECT 'Wooden Worksheet: Sequencing Colours and Shapes', 'Shelf for works sheets', 123
    UNION ALL
    SELECT 'Wooden Worksheet: Sequencing Patterns', 'Shelf for works sheets', 124
    UNION ALL
    SELECT 'Wooden Worksheet: Sizes', 'Shelf for works sheets', 125
    UNION ALL
    SELECT 'Wooden Worksheet: Visual Discrimination', 'Shelf for works sheets', 126
    UNION ALL
    SELECT 'Wooden Worksheet: Geometry Complex', 'Shelf for works sheets', 127
    UNION ALL
    SELECT 'Wooden Worksheet: Geometry Simple', 'Shelf for works sheets', 128
    UNION ALL
    SELECT 'Ulitim map stand', 'Shelf for works sheets', 129
    UNION ALL
    SELECT 'Asia puzzle map jumbo', 'Shelf for works sheets', 130
    UNION ALL
    SELECT 'India puzzle map jumbo', 'Shelf for works sheets', 131
    UNION ALL
    SELECT 'India state map', 'Shelf for works sheets', 132
    UNION ALL
    SELECT 'Cloth peg stand', 'Shelf for works sheets', 133
    UNION ALL
    SELECT 'Multiplication bar', 'Materials in room shelf', 134
    UNION ALL
    SELECT 'Direction game including compass', 'Materials in room shelf', 135
    UNION ALL
    SELECT 'Kannada alphabet', 'Materials in room shelf', 136
    UNION ALL
    SELECT 'Fraction game stand', 'Materials in room shelf', 137
    UNION ALL
    SELECT 'Chair -16', 'Materials in room shelf', 138
    UNION ALL
    SELECT 'Table-4', 'Materials in room shelf', 139
    UNION ALL
    SELECT 'Rolling mat-20', 'Materials in room shelf', 140
    UNION ALL
    SELECT 'Sitting mat—30', 'Materials in room shelf', 141
    UNION ALL
    SELECT 'Rolling mat stand—1', 'Materials in room shelf', 142
    UNION ALL
    SELECT 'Reading bookshelf-3', 'Materials in room shelf', 143
    UNION ALL
    SELECT 'Flash card/picture card stand-2', 'Materials in room shelf', 144
    UNION ALL
    SELECT 'Black board-', 'Materials in room shelf', 145
    UNION ALL
    SELECT 'Birthday chart-', 'Materials in room shelf', 146
    UNION ALL
    SELECT 'Butterfly board', 'Materials in room shelf', 147
) seed
WHERE NOT EXISTS (SELECT 1 FROM mm_materials LIMIT 1);
