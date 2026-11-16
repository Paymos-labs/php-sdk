<?php

declare(strict_types=1);

use Paymos\Plugin\InvoicePresentation;

function test_invoice_presentation_normalizes_valid_product()
{
    $item = InvoicePresentation::normalizeProduct(
        'Studio Pro',
        'One-month access',
        'https://shop.example/images/studio-pro.webp',
        2,
        '24.50'
    );

    assertSameValue(
        array(
            'title' => 'Studio Pro',
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => 'One-month access',
            'image_url' => 'https://shop.example/images/studio-pro.webp',
        ),
        $item,
        'Valid product must keep all normalized fields with int quantity and string unit_amount.'
    );
}

function test_invoice_presentation_omits_blank_description_and_invalid_image_url_without_failing()
{
    $noDescriptionHttpImage = InvoicePresentation::normalizeProduct(
        'Priority Support',
        "<p>&nbsp;\xc2\xa0</p>",
        'http://shop.example/images/insecure.png',
        '1',
        '12.00'
    );

    assertSameValue(
        array(
            'title' => 'Priority Support',
            'quantity' => 1,
            'unit_amount' => '12.00',
        ),
        $noDescriptionHttpImage,
        'Blank HTML description and HTTP image_url must be omitted rather than rejecting the item.'
    );

    $badImages = array(
        null,
        '',
        '   ',
        'https://',
        'https:///missing-host.png',
        '//shop.example/protocol-relative.png',
        'data:image/png;base64,AAAA',
        'javascript:alert(1)',
        'https://user:pass@shop.example/img.png',
        'https://user@shop.example/img.png',
        'https://@shop.example/img.png',
        'https://shop.example/back\\slash.png',
        'https://shop.example/has space.png',
        "https://shop.example/ctrl\x07.png",
        'https://shop.example/' . str_repeat('a', 2028),
        'https://shop.example/' . str_repeat('a', 2030) . '.png',
        "https://shop.example/bad\xFFutf8.png",
    );

    foreach ($badImages as $badImage) {
        $result = InvoicePresentation::normalizeProduct('Item', null, $badImage, 1, '1.00');
        assertTrueValue(is_array($result), 'Invalid image_url must not fail the product item.');
        assertFalseValue(array_key_exists('image_url', $result), 'Invalid image_url must be omitted.');
    }

    $maxImageUrl = 'https://shop.example/' . str_repeat('a', 2027);
    $withMaxImage = InvoicePresentation::normalizeProduct('Item', null, 'HTTPS://shop.example/a.webp?v=1#top', 1, '1.00');
    assertSameValue('HTTPS://shop.example/a.webp?v=1#top', $withMaxImage['image_url'], 'Valid HTTPS URL must be preserved.');

    $exact2048 = InvoicePresentation::normalizeProduct('Item', null, $maxImageUrl, 1, '1.00');
    assertSameValue($maxImageUrl, $exact2048['image_url'], '2048-scalar HTTPS URL must be preserved.');
}

function test_invoice_presentation_normalizes_html_nul_and_unicode_boundaries()
{
    $cleaned = InvoicePresentation::normalizeProduct(
        "  <b>Canvas</b> &amp; Blue\0&#39;s\r\n\tEdition\xc2\xa0",
        "<p>Line 1</p><div class=\"x\">Line 2</div><ul><li>Line 3<br />Line 4</li></ul>\r\n&nbsp;",
        '  https://shop.example/canvas.png  ',
        2,
        ' 24.50 '
    );

    assertSameValue(
        array(
            'title' => "Canvas & Blue's Edition",
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => "Line 1\nLine 2\nLine 3\nLine 4",
            'image_url' => 'https://shop.example/canvas.png',
        ),
        $cleaned,
        'Title and description must clean HTML, decode entities, strip NUL, and trim Unicode whitespace.'
    );

    $emoji200 = str_repeat("\xF0\x9F\x9A\x80", 200);
    $atLimit = InvoicePresentation::normalizeProduct($emoji200, null, null, 1, '10.00');
    assertSameValue($emoji200, $atLimit['title'], 'Title with exactly 200 4-byte Unicode scalars must pass.');

    $emoji201 = str_repeat("\xF0\x9F\x9A\x80", 201);
    assertSameValue(null, InvoicePresentation::normalizeProduct($emoji201, null, null, 1, '10.00'), 'Title above 200 Unicode scalars must return null.');

    assertSameValue(null, InvoicePresentation::normalizeProduct("<b>&nbsp;</b>\0", null, null, 1, '10.00'), 'Blank title after HTML/entity/NUL cleanup must return null.');
    assertSameValue(null, InvoicePresentation::normalizeProduct("Bad\xFFTitle", null, null, 1, '10.00'), 'Invalid UTF-8 title must return null.');

    $desc2005 = str_repeat('a', 1998) . "  \xF0\x9F\x9A\x80" . 'bbbb';
    $truncatedDesc = InvoicePresentation::normalizeProduct('Valid', $desc2005, "bad\xFFdesc", 1, '5.00');
    assertSameValue(str_repeat('a', 1998), $truncatedDesc['description'], 'Description > 2000 scalars must truncate to 2000 and re-trim trailing whitespace without failing item.');
    assertFalseValue(array_key_exists('image_url', $truncatedDesc), 'Invalid UTF-8 image_url must be omitted.');

    $desc2001Emojis = str_repeat("\xF0\x9F\x9A\x80", 2001);
    $truncatedEmojiDesc = InvoicePresentation::normalizeProduct('Valid', $desc2001Emojis, null, 1, '5.00');
    assertSameValue(str_repeat("\xF0\x9F\x9A\x80", 2000), $truncatedEmojiDesc['description'], 'Description > 2000 multi-byte scalars must truncate at exactly 2000 Unicode scalars.');

    $descOnlySpaces = str_repeat(' ', 2005);
    $omittedSpacesDesc = InvoicePresentation::normalizeProduct('Valid', $descOnlySpaces, null, 1, '5.00');
    assertFalseValue(array_key_exists('description', $omittedSpacesDesc), 'Whitespace-only description must be omitted.');

    $invalidUtf8Desc = InvoicePresentation::normalizeProduct('Valid', "Bad\xFFDescription", null, 1, '5.00');
    assertTrueValue(is_array($invalidUtf8Desc), 'Invalid UTF-8 description must be omitted without failing item.');
    assertFalseValue(array_key_exists('description', $invalidUtf8Desc), 'Invalid UTF-8 description key must be omitted.');
}

function test_invoice_presentation_rejects_invalid_quantity_and_unit_amount()
{
    assertSameValue(
        2147483647,
        InvoicePresentation::normalizeProduct('MaxQty', null, null, '2147483647', '0')['quantity'],
        'Int32 max quantity string and zero unit_amount must be accepted.'
    );
    assertSameValue(
        '0.00',
        InvoicePresentation::normalizeProduct('FreeItem', null, null, 1, '0.00')['unit_amount'],
        '0.00 unit_amount must be accepted.'
    );
    $maxDecimal = '12345678901234567890.123456789012345678';
    assertSameValue(
        $maxDecimal,
        InvoicePresentation::normalizeProduct('HighPrecision', null, null, 1, $maxDecimal)['unit_amount'],
        '39-character decimal unit_amount (20 integer + 18 fractional digits) must be preserved.'
    );

    $badQuantities = array(null, 0, -1, 2147483648, 1.5, 2.0, '0', '-1', '1.5', '01', ' 2 ', '2147483648', '', true, array());
    foreach ($badQuantities as $badQty) {
        assertSameValue(
            null,
            InvoicePresentation::normalizeProduct('Valid', null, null, $badQty, '10.00'),
            'Invalid quantity must return null.'
        );
    }

    $badAmounts = array(null, '', '   ', '-1.00', '+1.00', '01.00', '.50', '1.', '1.5e2', '1,50', "10.00\0", "10\0.00", str_repeat('9', 21), '1.' . str_repeat('0', 19));
    foreach ($badAmounts as $badAmount) {
        assertSameValue(
            null,
            InvoicePresentation::normalizeProduct('Valid', null, null, 1, $badAmount),
            'Invalid unit_amount must return null.'
        );
    }
}

function test_invoice_presentation_normalize_products_all_or_nothing_and_max_items()
{
    assertSameValue(50, InvoicePresentation::MAX_ITEMS, 'MAX_ITEMS must equal 50.');
    assertSameValue(null, InvoicePresentation::normalizeProducts(array()), 'Empty array must return null.');
    assertSameValue(null, InvoicePresentation::normalizeProducts(new \ArrayIterator(array())), 'Empty iterator must return null.');

    $cartIterator = new \ArrayIterator(array(
        array(
            'title' => 'Canvas · Blue',
            'description' => '<p>Giclée print</p>',
            'image_url' => 'https://shop.example/canvas.webp',
            'quantity' => 2,
            'unit_amount' => '15.00',
        ),
        array(
            'title' => 'Notebook',
            'description' => '&nbsp;',
            'image_url' => 'http://shop.example/insecure.png',
            'quantity' => '1',
            'unit_amount' => '12.50',
        ),
    ));

    $normalizedCart = InvoicePresentation::normalizeProducts($cartIterator);
    assertSameValue(
        array(
            array(
                'title' => 'Canvas · Blue',
                'quantity' => 2,
                'unit_amount' => '15.00',
                'description' => 'Giclée print',
                'image_url' => 'https://shop.example/canvas.webp',
            ),
            array(
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
        $normalizedCart,
        'Valid multi-item iterable cart must preserve all items and omit optional blank/non-HTTPS fields per item.'
    );

    $fifty = array();
    for ($i = 1; $i <= 50; $i++) {
        $fifty[] = array(
            'title' => 'Item ' . $i,
            'quantity' => 1,
            'unit_amount' => '1.00',
        );
    }
    $normalizedFifty = InvoicePresentation::normalizeProducts($fifty);
    assertSameValue(50, count($normalizedFifty), 'Exactly 50 valid items must be accepted.');

    $fiftyOne = $fifty;
    $fiftyOne[] = array('title' => 'Item 51', 'quantity' => 1, 'unit_amount' => '1.00');
    assertSameValue(null, InvoicePresentation::normalizeProducts($fiftyOne), '51 items must trigger all-or-nothing null fallback.');

    $cartWithFractionalQty = array(
        array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
        array('title' => 'Fabric', 'quantity' => 1.5, 'unit_amount' => '10.00'),
    );
    assertSameValue(null, InvoicePresentation::normalizeProducts($cartWithFractionalQty), 'Any invalid item (fractional qty) must fall back entire cart to null.');

    $cartWithNonArray = array(
        array('title' => 'Valid Shirt', 'quantity' => 1, 'unit_amount' => '20.00'),
        'invalid-row',
    );
    assertSameValue(null, InvoicePresentation::normalizeProducts($cartWithNonArray), 'Any non-array candidate must fall back entire cart to null.');
}
