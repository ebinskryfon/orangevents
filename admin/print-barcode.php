<?php
require_once __DIR__ . '/../config/database.php';

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$db = get_db_connection();
$variant_id = (int)($_GET['variant_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);
$all = (int)($_GET['all'] ?? 0);

$variants = [];

if ($variant_id > 0) {
    $stmt = $db->prepare("
        SELECT v.*, p.product_name, p.base_price, c.category_name
          FROM billing_product_variants v
          JOIN billing_products p ON v.product_id = p.id
          JOIN billing_categories c ON p.category_id = c.id
         WHERE v.id = :id
    ");
    $stmt->execute(['id' => $variant_id]);
    $res = $stmt->fetch();
    if ($res) {
        $variants[] = $res;
    }
} elseif ($product_id > 0) {
    $stmt = $db->prepare("
        SELECT v.*, p.product_name, p.base_price, c.category_name
          FROM billing_product_variants v
          JOIN billing_products p ON v.product_id = p.id
          JOIN billing_categories c ON p.category_id = c.id
         WHERE v.product_id = :product_id
         ORDER BY v.id ASC
    ");
    $stmt->execute(['product_id' => $product_id]);
    $variants = $stmt->fetchAll();
} elseif ($all > 0) {
    $stmt = $db->prepare("
        SELECT v.*, p.product_name, p.base_price, c.category_name
          FROM billing_product_variants v
          JOIN billing_products p ON v.product_id = p.id
          JOIN billing_categories c ON p.category_id = c.id
         ORDER BY p.product_name ASC, v.id ASC
    ");
    $stmt->execute();
    $variants = $stmt->fetchAll();
}

if (empty($variants)) {
    die("No variants found to print.");
}

// Function to generate Code 39 barcode SVG
function generateCode39SVG($text) {
    $patterns = [
        '0' => 'NnNwWnNnW', '1' => 'WnNwNnNnW', '2' => 'NnWwNnNnW', '3' => 'WnWwNnNnN',
        '4' => 'NnNwWnNnN', '5' => 'WnNwWnNnN', '6' => 'NnWwWnNnN', '7' => 'NnNwNnWnW',
        '8' => 'WnNwNnWnN', '9' => 'NnWwNnWnN', 'A' => 'WnNnWnNwW', 'B' => 'NnWnWnNwW',
        'C' => 'WnWnWnNwN', 'D' => 'NnNnWwNwW', 'E' => 'WnNnWwNwN', 'F' => 'NnWnWwNwN',
        'G' => 'NnNnNnWwW', 'H' => 'WnNnNnWwN', 'I' => 'NnWnNnWwN', 'J' => 'NnNnWnWwN',
        'K' => 'WnNnNnNnWw', 'L' => 'NnWnNnNnWw', 'M' => 'WnWnNnNnWn', 'N' => 'NnNnWnNnWw',
        'O' => 'WnNnWnNnWn', 'P' => 'NnWnWnNnWn', 'Q' => 'NnNnNnWnWw', 'R' => 'WnNnNnWnWn',
        'S' => 'NnWnNnWnWn', 'T' => 'NnNnWnWnWn', 'U' => 'WwNnNnNnNnW', 'V' => 'NwWnNnNnNnW',
        'W' => 'WwWnNnNnNnN', 'X' => 'NwNnWnNnNnW', 'Y' => 'WwNnWnNnNnN', 'Z' => 'NwWnWnNnNnN',
        '-' => 'NwNnNnWnNnW', '.' => 'WwNnNnWnNnN', ' ' => 'NwWnNnWnNnN', '*' => 'NwNnWnWnNnN',
        '$' => 'NwNwNwNnNnN', '/' => 'NwNwNnNwNnN', '+' => 'NwNnNwNwNnN', '%' => 'NnNwNwNwNnN'
    ];

    $text = '*' . strtoupper($text) . '*';
    $svg = '';
    
    // Narrow module = 1.3px, Wide module = 3.5px
    $narrow = 1.3;
    $wide = 3.5;
    
    $x = 0;
    $height = 42;
    
    for ($i = 0; $i < strlen($text); $i++) {
        $char = $text[$i];
        if (!isset($patterns[$char])) continue;
        
        $pattern = $patterns[$char];
        
        for ($j = 0; $j < strlen($pattern); $j++) {
            $symbol = $pattern[$j];
            $is_bar = ($j % 2 === 0);
            
            $width = ($symbol === 'W' || $symbol === 'w') ? $wide : $narrow;
            
            if ($is_bar) {
                $svg .= "<rect x='{$x}' y='0' width='{$width}' height='{$height}' fill='#000000' />";
            }
            $x += $width;
        }
        $x += $narrow; // Inter-character gap
    }
    
    return "<svg width='{$x}' height='{$height}' viewBox='0 0 {$x} {$height}' xmlns='http://www.w3.org/2000/svg'>{$svg}</svg>";
}

$js_variants = [];
foreach ($variants as $item) {
    $price = $item['price'] !== null ? $item['price'] : $item['base_price'];
    $js_variants[] = [
        'product_name' => $item['product_name'],
        'size' => $item['size'],
        'barcode' => $item['barcode'],
        'price' => (float)$price,
        'svg' => generateCode39SVG($item['barcode'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcode Stickers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --text-color: #f8fafc;
            --accent-color: #ff6b35;
            --border-color: #334155;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Control Panel */
        .control-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem 2rem;
            max-width: 500px;
            width: 100%;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .panel-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #94a3b8;
        }

        .form-control {
            background: #0f172a;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: white;
            padding: 0.75rem;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--accent-color);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
        }

        .btn {
            flex: 1;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: transparent;
            border: 1px solid var(--border-color);
            color: #e2e8f0;
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.05);
        }

        /* Stickers Area */
        .stickers-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, 50mm);
            gap: 10px;
            justify-content: center;
            background: #334155;
            padding: 20px;
            border-radius: 12px;
            border: 1px dashed var(--border-color);
            max-width: 90%;
        }

        /* Sticker Box (50mm x 25mm standard size) */
        .sticker {
            width: 50mm;
            height: 25mm;
            background: white;
            color: black;
            box-sizing: border-box;
            padding: 2mm 3mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
            border-radius: 2px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .sticker-title {
            font-size: 7.5pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            margin: 0;
            line-height: 1;
        }

        .sticker-subtitle {
            font-size: 5.5pt;
            font-weight: 600;
            color: #444;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            margin: 0;
            line-height: 1;
        }

        .barcode-graphic {
            margin: 1.5mm 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 10mm;
            width: 100%;
        }

        .barcode-graphic svg {
            max-height: 100%;
            max-width: 100%;
        }

        .sticker-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            font-size: 5.5pt;
            font-weight: 700;
            margin: 0;
            line-height: 1;
        }

        .sticker-code {
            font-family: monospace;
            font-size: 6pt;
        }

        .sticker-price {
            font-size: 6.5pt;
            font-weight: 800;
        }

        /* Print Styles */
        @media print {
            body {
                background: white !important;
                color: black !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .control-panel {
                display: none !important;
            }

            .stickers-container {
                display: block !important;
                background: white !important;
                padding: 0 !important;
                border: none !important;
                margin: 0 !important;
                max-width: 100% !important;
            }

            .sticker {
                float: left;
                box-shadow: none !important;
                border: 1px solid #ddd; /* subtle cut border for label sheets */
                page-break-inside: avoid;
                margin: 1px;
            }

            @page {
                size: auto;
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <div class="control-panel">
        <h2 class="panel-title">
            <i class="fa-solid fa-print"></i> Bulk Barcode Label Generator
        </h2>
        <div style="font-size:0.85rem; color:#94a3b8; line-height:1.4;">
            Printing labels for <strong><?= count($variants) ?></strong> unique variant(s).
        </div>
        
        <div class="form-group">
            <label class="form-label" for="copies">Copies per Variant</label>
            <input type="number" id="copies" class="form-control" min="1" max="100" value="4" oninput="updateStickerCount()">
        </div>

        <div class="btn-group">
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fa-solid fa-times"></i> Close
            </button>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fa-solid fa-print"></i> Print Labels
            </button>
        </div>
    </div>

    <div class="stickers-container" id="stickersContainer">
        <!-- Generated Dynamically -->
    </div>

    <script>
        const variants = <?= json_encode($js_variants) ?>;

        function updateStickerCount() {
            const copiesInput = document.getElementById('copies');
            let count = parseInt(copiesInput.value) || 1;
            if (count < 1) count = 1;
            if (count > 100) count = 100;
            
            const container = document.getElementById('stickersContainer');
            container.innerHTML = '';
            
            variants.forEach(v => {
                const stickerHTML = `
                    <div class="sticker">
                        <div class="sticker-title">Orange Events</div>
                        <div class="sticker-subtitle">${escapeHtml(v.product_name)} (${escapeHtml(v.size)})</div>
                        <div class="barcode-graphic">
                            ${v.svg}
                        </div>
                        <div class="sticker-footer">
                            <span class="sticker-code">${escapeHtml(v.barcode)}</span>
                            <span class="sticker-price">MRP: Rs.${Math.round(v.price)}</span>
                        </div>
                    </div>
                `;
                
                for (let i = 0; i < count; i++) {
                    container.innerHTML += stickerHTML;
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text
                .toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Initialize copies on load
        window.addEventListener('DOMContentLoaded', () => {
            updateStickerCount();
        });
    </script>
</body>
</html>
