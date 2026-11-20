<?php

declare(strict_types=1);

use Paymos\Plugin\InvoicePresentation;

function test_invoice_presentation_normalizes_valid_product()
{
    $item = InvoicePresentation::normalizeProduct(
        'Studio Pro',
        'One-month access',
        2,
        '24.50'
    );

    assertSameValue(
        array(
            'title' => 'Studio Pro',
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => 'One-month access',
        ),
        $item,
        'Valid product must keep all normalized fields with int quantity and string unit_amount.'
    );
}

function test_invoice_presentation_omits_blank_description_without_failing()
{
    $noDescription = InvoicePresentation::normalizeProduct(
        'Priority Support',
        "<p>&nbsp;\xc2\xa0</p>",
        '1',
        '12.00'
    );

    assertSameValue(
        array(
            'title' => 'Priority Support',
            'quantity' => 1,
            'unit_amount' => '12.00',
        ),
        $noDescription,
        'Blank HTML description must be omitted rather than rejecting the item.'
    );
}

function test_invoice_presentation_normalizes_html_nul_and_unicode_boundaries()
{
    $cleaned = InvoicePresentation::normalizeProduct(
        "  <b>Canvas</b> &amp; Blue\0&#39;s\r\n\tEdition\xc2\xa0",
        "<p>Line 1</p><div class=\"x\">Line 2</div><ul><li>Line 3<br />Line 4</li></ul>\r\n&nbsp;",
        2,
        ' 24.50 '
    );

    assertSameValue(
        array(
            'title' => "Canvas & Blue's Edition",
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => "Line 1\nLine 2\nLine 3\nLine 4",
        ),
        $cleaned,
        'Title and description must clean HTML, decode entities, strip NUL, and trim Unicode whitespace.'
    );

    $emoji200 = str_repeat("\xF0\x9F\x9A\x80", 200);
    $atLimit = InvoicePresentation::normalizeProduct($emoji200, null, 1, '10.00');
    assertSameValue($emoji200, $atLimit['title'], 'Title with exactly 200 4-byte Unicode scalars must pass.');

    $emoji201 = str_repeat("\xF0\x9F\x9A\x80", 201);
    assertSameValue(null, InvoicePresentation::normalizeProduct($emoji201, null, 1, '10.00'), 'Title above 200 Unicode scalars must return null.');

    assertSameValue(null, InvoicePresentation::normalizeProduct("<b>&nbsp;</b>\0", null, 1, '10.00'), 'Blank title after HTML/entity/NUL cleanup must return null.');
    assertSameValue(null, InvoicePresentation::normalizeProduct("Bad\xFFTitle", null, 1, '10.00'), 'Invalid UTF-8 title must return null.');

    $desc2005 = str_repeat('a', 1998) . "  \xF0\x9F\x9A\x80" . 'bbbb';
    $truncatedDesc = InvoicePresentation::normalizeProduct('Valid', $desc2005, 1, '5.00');
    assertSameValue(str_repeat('a', 1998), $truncatedDesc['description'], 'Description > 2000 scalars must truncate to 2000 and re-trim trailing whitespace without failing item.');

    $desc2001Emojis = str_repeat("\xF0\x9F\x9A\x80", 2001);
    $truncatedEmojiDesc = InvoicePresentation::normalizeProduct('Valid', $desc2001Emojis, 1, '5.00');
    assertSameValue(str_repeat("\xF0\x9F\x9A\x80", 2000), $truncatedEmojiDesc['description'], 'Description > 2000 multi-byte scalars must truncate at exactly 2000 Unicode scalars.');

    $descOnlySpaces = str_repeat(' ', 2005);
    $omittedSpacesDesc = InvoicePresentation::normalizeProduct('Valid', $descOnlySpaces, 1, '5.00');
    assertFalseValue(array_key_exists('description', $omittedSpacesDesc), 'Whitespace-only description must be omitted.');

    $invalidUtf8Desc = InvoicePresentation::normalizeProduct('Valid', "Bad\xFFDescription", 1, '5.00');
    assertTrueValue(is_array($invalidUtf8Desc), 'Invalid UTF-8 description must be omitted without failing item.');
    assertFalseValue(array_key_exists('description', $invalidUtf8Desc), 'Invalid UTF-8 description key must be omitted.');
}

function test_invoice_presentation_rejects_invalid_quantity_and_unit_amount()
{
    assertSameValue(
        2147483647,
        InvoicePresentation::normalizeProduct('MaxQty', null, '2147483647', '0')['quantity'],
        'Int32 max quantity string and zero unit_amount must be accepted.'
    );
    assertSameValue(
        '0.00',
        InvoicePresentation::normalizeProduct('FreeItem', null, 1, '0.00')['unit_amount'],
        '0.00 unit_amount must be accepted.'
    );
    $maxDecimal = '12345678901234567890.123456789012345678';
    assertSameValue(
        $maxDecimal,
        InvoicePresentation::normalizeProduct('HighPrecision', null, 1, $maxDecimal)['unit_amount'],
        '39-character decimal unit_amount (20 integer + 18 fractional digits) must be preserved.'
    );

    $badQuantities = array(null, 0, -1, 2147483648, 1.5, 2.0, '0', '-1', '1.5', '01', ' 2 ', '2147483648', '', true, array());
    foreach ($badQuantities as $badQty) {
        assertSameValue(
            null,
            InvoicePresentation::normalizeProduct('Valid', null, $badQty, '10.00'),
            'Invalid quantity must return null.'
        );
    }

    $badAmounts = array(null, '', '   ', '-1.00', '+1.00', '01.00', '.50', '1.', '1.5e2', '1,50', "10.00\0", "10\0.00", str_repeat('9', 21), '1.' . str_repeat('0', 19));
    foreach ($badAmounts as $badAmount) {
        assertSameValue(
            null,
            InvoicePresentation::normalizeProduct('Valid', null, 1, $badAmount),
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
            'quantity' => 2,
            'unit_amount' => '15.00',
        ),
        array(
            'title' => 'Notebook',
            'description' => '&nbsp;',
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
            ),
            array(
                'title' => 'Notebook',
                'quantity' => 1,
                'unit_amount' => '12.50',
            ),
        ),
        $normalizedCart,
        'Valid multi-item iterable cart must preserve all items and omit blank descriptions per item.'
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
