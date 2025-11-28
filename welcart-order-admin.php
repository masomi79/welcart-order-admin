<?php
/*
Plugin Name: Welcart Order Admin
Description: Welcartの受注管理を表示するプラグイン
Version: 1.49
Author: masomi79
*/

/*
各データとvwp_usces_orderテーブル内のフィールド名
注文番号,ID
受注日,order_date
支払い方法,order_payment_name
総合計金額(円),order_item_total_price
会員No.,mem_id
Eメール,order_email
備考,order_note
クーポンコード,order_discount
対応状況＆入金状況,order_status

対応状況、入金情報の文字列はカンマ区切りのの値で、以下のような値が入る
adminorder,
cancel, 
cancel,noreceipt, 
cancel,pending, 
cancel,receipted, 
completion,adminorder, 
completion,receipted,adminorder, 
noreceipt, 
noreceipt,pending, 
pending,receipted

adominorder の意味は不明。ここでは使用しない。
一つ目の文字列は対応状況、二つ目の文字列は入金状況を表す。
それぞれの文字列は以下のような意味を持つ
対応状況:
[空白]：新規受付中,
duringorder:取り寄せ中
cancel:キャンセル
completion:発送済み
入金状況:
noreceipt:未入金
receipted:入金済み
pending:Pending

↓メタテーブル(vwp_usces_order_meta)から取得する値
管理者メモ,"meta_key:"order_memo"
決済ID,"meta_key:"settlement_id"
クーポン値,"meta_key:"csod_coupon"

20251128 注文詳細画面でのクーポン割引の扱い
オリジナルの管理画面では総合計金額は直接更新せず、都度画面上でクーポン割引を差し引いて表示しているとみられる。
この方法をプラグインでも踏襲する


総合計金額：order_item_total_price (decimal(10,2))
クーポン割引：order_discount (decimal(10,2))
order_tax (decimal(10,2))

*/

// 安全対策: WordPressの環境でのみ動作させる
if ( !defined('ABSPATH') ) {
    exit;
}

// 追加: 空のGETパラメータを除外する
add_action('admin_init', function () {
    if ( isset($_GET['page']) && $_GET['page'] === 'welcart-order-admin' ) {
        $_GET = array_filter($_GET, function($value) {
            return $value !== '';
        });
    }
}, 1);

// 管理画面にCSSとJavaScriptを読み込む
add_action('admin_enqueue_scripts', function($hook) {
    // 一時的に条件を外して全ページで読み込む
    wp_enqueue_style(
        'welcart-order-admin-style',
        plugins_url('css/welcart-order-admin-style.css', __FILE__),
        array(), '1.0.0'
    );
    wp_enqueue_script(
        'welcart-order-admin-scripts',
        plugins_url('js/welcart-order-admin-scripts.js', __FILE__),
        array('jquery'),
        '1.0.0',
        true
    );
});

// 検索条件を生成する関数
function generate_search_conditions($wpdb) {
    $field1 = isset($_GET['field1']) ? sanitize_text_field($_GET['field1']) : '';
    $operator1 = isset($_GET['operator1']) ? sanitize_text_field($_GET['operator1']) : '';
    $value1 = isset($_GET['value1']) ? sanitize_text_field($_GET['value1']) : '';
    $payment_method1 = isset($_GET['payment_method1']) ? sanitize_text_field($_GET['payment_method1']) : '';
    $field2 = isset($_GET['search_field2']) ? sanitize_text_field($_GET['search_field2']) : '';
    $operator2 = isset($_GET['operator2']) ? sanitize_text_field($_GET['operator2']) : '';
    $value2 = isset($_GET['search_value2']) ? sanitize_text_field($_GET['search_value2']) : '';
    $payment_method2 = isset($_GET['payment_method2']) ? sanitize_text_field($_GET['payment_method2']) : '';
    $bool_operator = isset($_GET['bool_operator']) ? sanitize_text_field($_GET['bool_operator']) : 'AND';

    $operator_map = [
        'equals'       => '=',
        'contains'     => 'LIKE',
        'not_equals'   => '!=',
        'greater_than' => '>',
        'less_than'    => '<'
    ];

    $where_clauses = [];

    // 条件1の処理
    if ($field1 === "order_payment_name" && $payment_method1 !== '') {
        $where_clauses[] = $wpdb->prepare("`$field1` = %s", $payment_method1);
    } elseif ($field1 === "csod_coupon" && $value1 !== '') {
        if ($operator1 === 'equals') {
            $where_clauses[] = $wpdb->prepare(
                "ID IN (SELECT order_id FROM {$wpdb->prefix}usces_order_meta WHERE meta_key = 'csod_coupon' AND meta_value = %s)",
                $value1
            );
        } elseif ($operator1 === 'contains') {
            $like_value = '%' . $wpdb->esc_like($value1) . '%';
            $where_clauses[] = $wpdb->prepare(
                "ID IN (SELECT order_id FROM {$wpdb->prefix}usces_order_meta WHERE meta_key = 'csod_coupon' AND meta_value LIKE %s)",
                $like_value
            );
        }
    } elseif ($field1 === "order_status") {
        $value1 = isset($_GET['order_status_select1']) ? sanitize_text_field($_GET['order_status_select1']) : '';
        if( $value1 === '' ) {
            return '1=1'; // 条件がない場合は常に真となる条件を返す
        }elseif( $value1 === 'recibido' ) { // 入金済み
            $where = "order_status LIKE '%receipted%' AND order_status NOT LIKE '%noreceipt%'";
        }elseif ( $value1 === 'norecibido' ) { // 未入金
            $where = "order_status LIKE '%noreceipt%'";
        }elseif ( $value1 === 'pendiente' ) { // Pending
            $where = "order_status LIKE '%pending%'";
        }else{ // その他の入金状況が指定された場合そのまま出力する
            $where = "order_status like '%$value1%'";
        }
        if ($where) $where_clauses[] = $where;
    } elseif ($field1 === "order_taio") {
        $value1 = isset($_GET['taio_status_select1']) ? sanitize_text_field($_GET['taio_status_select1']) : '';
        if( $value1 === '' ) {
            return '1=1'; // 条件がない場合は常に真となる条件を返す
        }elseif ( $value1 === 'enproceso' ) { // 取り寄せ中
            $where = "order_status LIKE '%duringorder%'";
        }elseif ( $value1 === 'cancelado' ) { // キャンセル
            $where = "order_status LIKE '%cancel%'";
        }elseif ( $value1 === 'cumplido' ) { // 発送済み
            $where = "order_status LIKE '%completion%'";
        }elseif( $value1 === 'nuevopedido' ) {
            $where = "order_status LIKE '%#none#%' OR order_status NOT LIKE '%duringorder%' AND order_status NOT LIKE '%cancel%' AND order_status NOT LIKE '%completion%'";
        }else{
            $where = "order_status like '%$value1%'";
        }
        if ($where) $where_clauses[] = $where;
    } elseif ($field1 && $field1 !== "csod_coupon" && isset($operator_map[$operator1]) && $value1 !== '') {
        $operator = $operator_map[$operator1];
        if ($operator === 'LIKE') {
            $like_value = '%' . $wpdb->esc_like($value1) . '%';
            $where_clauses[] = $wpdb->prepare("`$field1` LIKE %s", $like_value);
        } else {
            $where_clauses[] = $wpdb->prepare("`$field1` $operator %s", $value1);
        }
    }

    // 条件2の処理
    if ($field2 === "order_payment_name" && $payment_method2 !== '') {
        $where_clauses[] = $wpdb->prepare("`$field2` = %s", $payment_method2);
    } elseif ($field2 === "csod_coupon" && $value2 !== '') {
        if ($operator2 === 'equals') {
            $where_clauses[] = $wpdb->prepare(
                "ID IN (SELECT order_id FROM {$wpdb->prefix}usces_order_meta WHERE meta_key = 'csod_coupon' AND meta_value = %s)",
                $value2
            );
        } elseif ($operator2 === 'contains') {
            $like_value2 = '%' . $wpdb->esc_like($value2) . '%';
            $where_clauses[] = $wpdb->prepare(
                "ID IN (SELECT order_id FROM {$wpdb->prefix}usces_order_meta WHERE meta_key = 'csod_coupon' AND meta_value LIKE %s)",
                $like_value2
            );
        }
    } elseif ($field2 === "order_status") {
        $value2 = isset($_GET['order_status_select2']) ? sanitize_text_field($_GET['order_status_select2']) : '';
        if( $value2 === '' ) {
            return '1=1'; // 条件がない場合は常に真となる条件を返す
        }elseif( $value2 === 'recibido' ) { // 入金済み
            $where = "order_status LIKE '%receipted%' AND order_status NOT LIKE '%noreceipt%'";
        }elseif ( $value2 === 'norecibido' ) { // 未入金
            $where = "order_status LIKE '%noreceipt%'";
        }elseif ( $value2 === 'pendiente' ) { // Pending
            $where = "order_status LIKE '%pending%'";
        }else{ // その他の入金状況が指定された場合そのまま出力する
            $where = "order_status like '%$value2%'";
        }
        if ($where) $where_clauses[] = $where;
    } elseif ($field2 === "order_taio") {
        $value1 = isset($_GET['taio_status_select2']) ? sanitize_text_field($_GET['taio_status_select2']) : '';
        if( $value2 === '' ) {
            return '1=1'; // 条件がない場合は常に真となる条件を返す
        }elseif ( $value2 === 'enproceso' ) { // 取り寄せ中
            $where = "order_status LIKE '%duringorder%'";
        }elseif ( $value2 === 'cancelado' ) { // キャンセル
            $where = "order_status LIKE '%cancel%'";
        }elseif ( $value2 === 'cumplido' ) { // 発送済み
            $where = "order_status LIKE '%completion%'";
        }elseif( $value2 === 'nuevopedido' ) { // 新規受付中
            $where = "order_status LIKE '%#none#%' OR order_status NOT LIKE '%duringorder%' AND order_status NOT LIKE '%cancel%' AND order_status NOT LIKE '%completion%'";
        }else{
            $where = "order_status like '%$value2%'";
        }
        if ($where) $where_clauses[] = $where;
    } elseif ($field2 && $field2 !== "csod_coupon" && isset($operator_map[$operator2]) && $value2 !== '') {
        $operator = $operator_map[$operator2];
        if ($operator === 'LIKE') {
            $like_value2 = '%' . $wpdb->esc_like($value2) . '%';
            $where_clauses[] = $wpdb->prepare("`$field2` LIKE %s", $like_value2);
        } else {
            $where_clauses[] = $wpdb->prepare("`$field2` $operator %s", $value2);
        }
    }

    return !empty($where_clauses) ? implode(" $bool_operator ", $where_clauses) : '1=1';
}

// CSV出力処理を admin_init フックで実行する
function handle_csv_export() {
    if ( ! isset($_GET['export_csv']) ) {
        return;
    }

    // 権限チェック: 管理者のみ許可
    if ( ! current_user_can('manage_options') ) {
        wp_die('権限がありません。');
    }

    // nonce チェック（GET フォームの nonce 名は _wpnonce_welcart_export）
    if ( ! isset($_GET['_wpnonce_welcart_export']) || ! check_admin_referer('welcart_export', '_wpnonce_welcart_export') ) {
        wp_die('不正なリクエストです。');
    }

    global $wpdb;

    // period パラメータを取得
    $selected_period = isset($_GET['period']) ? $_GET['period'] : '';

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=orders_export.csv");
    $fp = fopen('php://output', 'w');

    // CSV出力用の項目オプション（フォームのチェックボックスと同じ）
    $fields_options = array(
        "ID"                    => "受注番号",
        "order_payment_name"    => "支払い方法",
        "order_status"          => "入金状況",
        "order_item_total_price"=> "総額",
        "mem_id"                => "会員No",
        "order_email"           => "Eメール",
        "csod_coupon"           => "クーポン",
        "order_note"            => "備考"
    );

    // フォームから送信されたチェックされた項目を取得（なければすべての項目）
    if ( isset($_GET['export_fields']) && is_array($_GET['export_fields']) ) {
        $selected_fields = array();
        foreach ($_GET['export_fields'] as $field) {
            $sanitized = sanitize_key($field);
            if ( array_key_exists($sanitized, $fields_options) ) {
                $selected_fields[] = $sanitized;
            }
        }
    } else {
        $selected_fields = array_keys($fields_options);
    }

    $period = '';
    if (!empty($selected_period) && $selected_period !== 'all') {
        $today = current_time('Y-m-d');
        $year = date('Y', strtotime($today));
        $month = date('m', strtotime($today));
        if ($selected_period === 'this_year') {
            $period = " AND order_date >= '{$year}-01-01' AND order_date <= '{$year}-12-31'";
        } elseif ($selected_period === 'last_year') {
            $last_year = $year - 1;
            $period = " AND order_date >= '{$last_year}-01-01' AND order_date <= '{$last_year}-12-31'";
        } elseif ($selected_period === 'this_month') {
            $period = " AND DATE_FORMAT(order_date, '%Y-%m') = '{$year}-{$month}'";
        } elseif ($selected_period === 'last_month') {
            $last_month = date('Y-m', strtotime('first day of last month'));
            $period = " AND DATE_FORMAT(order_date, '%Y-%m') = '{$last_month}'";
        } elseif ($selected_period === 'before_last_month') {
            $before_last_month = date('Y-m', strtotime('first day of -2 month'));
            $period = " AND DATE_FORMAT(order_date, '%Y-%m') = '{$before_last_month}'";
        }
    }

    // 検索条件の生成（既存の関数を利用）
    $where_sql = generate_search_conditions($wpdb);
    if ($period) {
        $where_sql .= $period;
    }

    // デバッグ用：抽出SQL文をCSVの先頭に書き込む（短くする）
    fputcsv($fp, array('SQL: ' . $where_sql));
    fputcsv($fp, array('Period: ' . $period));

    // ヘッダー行の出力
    $header = array();
    $header[] = '受注番号'; // 先頭に追加
    foreach ($selected_fields as $field) {
        $header[] = $fields_options[$field];
    }
    fputcsv($fp, $header);

    // チャンク方式で出力する（メモリ保護のため）
    // 注意: $where_sql は generate_search_conditions の出力をそのまま使うため、安全化は別作業で行う（今回のパッチはチャンク化＆権限／nonce の追加が目的）
    $chunk = 1000; // 1回で取得する行数（必要に応じて小さくしてください）
    $offset = 0;

    // 無制限の長時間処理になる可能性があるためタイムアウト解除
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    while (true) {
        $limit = intval($chunk);
        $ofs = intval($offset);

        // SELECT は必要最小限の列だけ取る（ただし csod_coupon は meta から取得）
        $sql = "SELECT * FROM {$wpdb->prefix}usces_order WHERE {$where_sql} ORDER BY ID DESC LIMIT {$limit} OFFSET {$ofs}";

        $export_orders = $wpdb->get_results($sql);

        if ( empty($export_orders) ) {
            break;
        }

        foreach ($export_orders as $order) {
            $row = array();
            $order_id = isset($order->ID) ? $order->ID : '';
            $row[] = $order_id;

            foreach ($selected_fields as $field) {
                if ($field === 'csod_coupon') {
                    $row[] = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT meta_value FROM {$wpdb->prefix}usces_order_meta WHERE order_id = %d AND meta_key = %s",
                            $order->ID,
                            'csod_coupon'
                        )
                    );
                } elseif ($field === 'order_item_total_price') {
                    $total   = isset($order->order_item_total_price) ? $order->order_item_total_price : 0;
                    $discount = isset($order->order_discount) ? $order->order_discount : 0;
                    $row[] = $total + $discount;
                } else {
                    $row[] = isset($order->$field) ? $order->$field : '';
                }
            }
            fputcsv($fp, $row);
        }

        // flush to the client and free memory
        if (function_exists('ob_flush')) { @ob_flush(); }
        if (function_exists('flush')) { @flush(); }

        // advance
        $offset += $chunk;
    }

    fclose($fp);
    exit;
}
add_action('admin_init', 'handle_csv_export',0);

// デバッグ用CSV出力関数
function debug_export_csv() {
    if ( ! isset($_GET['export_hello']) ) {
        return;
    }
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=test.csv");
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['hello']);
    fclose($fp);
    exit;
}
add_action('admin_init', 'debug_export_csv');

// 受注リストを取得して表示する関数
function custom_show_welcart_orders() {

    if(isset($_GET['export_csv'])) {
        handle_csv_export();
        exit;
    }

    global $wpdb;

    echo '<div class="wrap">';
    echo '<h2>Welcartの受注管理</h2>';



    /* 
    検索フォームの生成
        id: search_form
    */
    echo '<div class="search-form-wrap">';
    echo '<form id="search_form" method="get" action="">';
    echo '<input type="hidden" name="page" value="welcart-order-admin">';
    $field1 = isset($_GET['field1']) ? esc_attr($_GET['field1']) : '';
    $operator1 = isset($_GET['operator1']) ? esc_attr($_GET['operator1']) : '';
    $value1 = isset($_GET['value1']) ? esc_attr($_GET['value1']) : '';
    $payment_method1 = isset($_GET['payment_method1']) ? esc_attr($_GET['payment_method1']) : '';
    $field2 = isset($_GET['search_field2']) ? esc_attr($_GET['search_field2']) : '';
    $operator2 = isset($_GET['operator2']) ? esc_attr($_GET['operator2']) : '';
    $value2 = isset($_GET['search_value2']) ? esc_attr($_GET['search_value2']) : '';
    $payment_method2 = isset($_GET['payment_method2']) ? esc_attr($_GET['payment_method2']) : '';
    $bool_operator = isset($_GET['bool_operator']) ? esc_attr($_GET['bool_operator']) : 'AND';


    $payment_options = [
        "クレジットカード（PAYPAL）",
        "銀行振込み（ゆうちょ銀行）",
        "銀行振込（PayPay銀行）",
        "微信（WeChat）",
        "银行汇款（中国工商银行）",
        "無料（FREE CHARGE）",
        "GMOあおぞらネット銀行"
    ];

    echo '<div style="margin-bottom: 10px;">';
    echo '<div class="search-field">';
    echo '<select name="field1" id="field1">';
    echo '<option value=""' . selected($field1, '', false) . '>フィールドを選択</option>';
    echo '<option value="ID"' . selected($field1, 'ID', false) . '>受注番号</option>';
    echo '<option value="order_payment_name"' . selected($field1, 'order_payment_name', false) . '>支払い方法</option>';
    echo '<option value="order_taio"' . selected($field1, 'order_taio', false) . '>対応状況</option>';
    echo '<option value="order_status"' . selected($field1, 'order_status', false) . '>入金状況</option>';
    echo '<option value="order_item_total_price"' . selected($field1, 'order_item_total_price', false) . '>金額</option>';
    echo '<option value="mem_id"' . selected($field1, 'mem_id', false) . '>会員No</option>';
    echo '<option value="order_email"' . selected($field1, 'order_email', false) . '>Eメール</option>';
    echo '<option value="order_note"' . selected($field1, 'order_note', false) . '>備考</option>';
    echo '<option value="csod_coupon"' . selected($field1, 'csod_coupon', false) . '>クーポン</option>';
    echo '</select>';
    echo '<select id="payment_method_select1" name="payment_method1" style="margin-left:10px; display:none;">';
    echo '<option value="">支払い方法を選択</option>';
    foreach ($payment_options as $option) {
        echo '<option value="' . esc_attr($option) . '"' . selected($payment_method1, $option, false) . '>' . esc_html($option) . '</option>';
    }
    echo '</select>';

    echo '<input class="search-text" type="text" name="value1" placeholder="検索条件" value="' . $value1 . '" style="margin-left: 10px;">';
    echo '<select name="operator1" style="margin-left: 10px;">';
    echo '<option value="equals"' . ($operator1 === 'equals' ? ' selected' : '') . '>と同じ</option>';
    echo '<option value="contains"' . ($operator1 === 'contains' ? ' selected' : '') . '>を含む</option>';
    echo '<option value="not_equals"' . ($operator1 === 'not_equals' ? ' selected' : '') . '>を含まない</option>';
    echo '<option value="greater_than"' . ($operator1 === 'greater_than' ? ' selected' : '') . '>より大きい</option>';
    echo '<option value="less_than"' . ($operator1 === 'less_than' ? ' selected' : '') . '>より小さい</option>';
    echo '</select>';
    //入金状況のセレクトボックス
    echo '<select name="order_status_select1" id="order_status_select1" style="display:none; margin-left: 10px;">';
    echo '<option value=""' . selected($order_status_select1, '', false) . '>入金状況を選択</option>';
    echo '<option value="recibido"' . selected($order_status_select1, 'reciido', false) . '>入金済み</option>';
    echo '<option value="norecibido"' . selected($order_status_select1, 'norecibido', false) . '>未入金</option>';
    echo '<option value="pendiente"' . selected($order_status_select1, 'pendiente', false) . '>Pending</option>';
    echo '</select>';
    //対応状況のセレクトボックス
    echo '<select name="taio_status_select1" id="taio_status_select1" style="display:none; margin-left: 10px;">';
    echo '<option value=""' . selected($order_status_select1, '', false) . '>対応状況を選択</option>';
    echo '<option value="nuevopedido"' . selected($order_status_select1, 'nuevopedido', false) . '>新規受付中</option>';
    echo '<option value="enproceso"' . selected($order_status_select1, 'enproceso', false) . '>取り寄せ中</option>';
    echo '<option value="cancelado"' . selected($order_status_select1, 'cancelado', false) . '>キャンセル</option>';
    echo '<option value="cumplido"' . selected($order_status_select1, 'cumplido', false) . '>発送済み</option>';
    echo '</select>';
    echo '</div>';

    // and/or セレクトボックスの追加
    echo '<div class="search-field">';
    echo '<select class="bool-operator" name="bool_operator">';
    echo '<option value="AND"' . ($bool_operator === 'AND' ? ' selected' : '') . '>AND</option>';
    echo '<option value="OR"' . ($bool_operator === 'OR' ? ' selected' : '') . '>OR</option>';
    echo '</select>';
    echo '</div>';

    // 検索条件2（フィールド1の複製）

    echo '<div class="search-field">';
    echo '<select name="search_field2" id="search_field2">';
    echo '<option value=""' . selected($field2, '', false) . '>フィールドを選択</option>';
    echo '<option value="ID"' . selected($field2, 'ID', false) . '>受注番号</option>';
    echo '<option value="order_payment_name"' . selected($field2, 'order_payment_name', false) . '>支払い方法</option>';
    echo '<option value="order_taio"' . selected($field2, 'order_taio', false) . '>対応状況</option>';
    echo '<option value="order_status"' . selected($field2, 'order_status', false) . '>入金状況</option>';
    echo '<option value="order_item_total_price"' . selected($field2, 'order_item_total_price', false) . '>金額</option>';
    echo '<option value="mem_id"' . selected($field2, 'mem_id', false) . '>会員No</option>';
    echo '<option value="order_email"' . selected($field2, 'order_email', false) . '>Eメール</option>';
    echo '<option value="order_note"' . selected($field2, 'order_note', false) . '>備考</option>';
    echo '<option value="csod_coupon"' . selected($field2, 'csod_coupon', false) . '>クーポン</option>';
    echo '</select>';
    echo '<select id="payment_method_select2" name="payment_method2" style="margin-left:10px; display:none;">';
    echo '<option value="">支払い方法を選択</option>';
    foreach ($payment_options as $option) {
        echo '<option value="' . esc_attr($option) . '"' . selected($payment_method2, $option, false) . '>' . esc_html($option) . '</option>';
    }
    echo '</select>';
    echo '<input class="search-text" type="text" name="search_value2" placeholder="検索条件" value="' . $value2 . '" style="margin-left: 10px;">';
    echo '<select name="operator2" style="margin-left: 10px;">';
    echo '<option value="equals"' . ($operator2 === 'equals' ? ' selected' : '') . '>と同じ</option>';
    echo '<option value="contains"' . ($operator2 === 'contains' ? ' selected' : '') . '>を含む</option>';
    echo '<option value="not_equals"' . ($operator2 === 'not_equals' ? ' selected' : '') . '>を含まない</option>';
    echo '<option value="greater_than"' . ($operator2 === 'greater_than' ? ' selected' : '') . '>より大きい</option>';
    echo '<option value="less_than"' . ($operator2 === 'less_than' ? ' selected' : '') . '>より小さい</option>';
    echo '</select>';
    //入金状況のセレクトボックス
    echo '<select name="order_status_select2" id="order_status_select2" style="display:none; margin-left: 10px;">';
    echo '<option value=""' . selected($order_status_select2, '', false) . '>入金状況を選択</option>';
    echo '<option value="recibido"' . selected($order_status_select2, 'adminorder', false) . '>入金済み</option>';
    echo '<option value="norecibido"' . selected($order_status_select2, 'cancel', false) . '>未入金</option>';
    echo '<option value="pendiente"' . selected($order_status_select2, 'cancel,noreceipt', false) . '>Pending</option>';
    echo '</select>';
    //対応状況のセレクトボックス
    echo '<select name="taio_status_select2" id="taio_status_select2" style="display:none; margin-left: 10px;">';
    echo '<option value=""' . selected($order_status_select2, '', false) . '>対応状況を選択</option>';
    echo '<option value="nuevopedido"' . selected($order_status_select2, 'nuevopedido', false) . '>新規受付中</option>';
    echo '<option value="enproceso"' . selected($order_status_select2, 'enproceso', false) . '>取り寄せ中</option>';
    echo '<option value="cancelado"' . selected($order_status_select2, 'cancelado', false) . '>キャンセル</option>';
    echo '<option value="cumplido"' . selected($order_status_select2, 'cumplido', false) . '>発送済み</option>';
    echo '</select>';
    echo '</div>';
    echo '</div>';
    echo '<input type="submit" value="検索開始" class="button">';
    echo ' <a href="#" id="clear_search_form" class="button">解除</a>';
    echo '</form>';
    echo '</div>';

    // ↓ CSV出力機能追加部分 (検索フォームとテーブルの間)
    echo '<button id="csv_export_btn" class="button" style="margin-top:10px;">CSV出力</button>';
    echo '<div id="csv_export_container" style="display:none; margin-top:10px;">';
    echo '<form id="csv_export_form" method="get" action="">';
    echo wp_nonce_field('welcart_export', '_wpnonce_welcart_export', true, false);
    echo '<input type="hidden" name="page" value="welcart-order-admin">';
    echo '<input type="hidden" name="export_csv" value="1">';

    // ここで現在のGETパラメータをhiddenで全て渡す
    foreach ($_GET as $key => $val) {
        // export_csv, page, export_fields[] は重複しないように除外
        if ($key === 'export_csv' || $key === 'page' || $key === 'export_fields') continue;
        if (is_array($val)) {
            foreach ($val as $v) {
                echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($v) . '">';
            }
        } else {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '">';
        }
    }

    // CSV項目選択チェックボックス
    $fields_options = array(
        "ID" => "受注番号",
        "order_payment_name" => "支払い方法",
        "order_status" => "入金状況",
        "order_item_total_price" => "総額",
        "mem_id" => "会員No",
        "order_email" => "Eメール",
        "csod_coupon" => "クーポン",
        "order_note" => "備考"
    );
    foreach ($fields_options as $key => $label) {
        echo '<label style="margin-right:10px;"><input type="checkbox" name="export_fields[]" value="'.$key.'" checked> '.$label.'</label>';
    }

    echo '<div class="search-field">';
    echo '<select name="period" id="period" style="margin-left:10px;">';
    $period_options = [
        ''         => '期間を選択',
        'this_year'=> '今年',
        'last_year'=> '昨年',
        'this_month'=> '今月',
        'last_month'=> '先月',
        'before_last_month'=> '先々月',
        'all'      => '全て'
    ];
    $selected_period = isset($_GET['period']) ? $_GET['period'] : '';
    foreach ($period_options as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($selected_period, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<br><button type="submit" class="button">CSVダウンロード</button>';
    echo '</form>';
    echo '</div>';
    // ↑ CSV出力機能追加部分

    // 現在のページ番号を取得
    $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $limit = 100;
    $offset = ($paged - 1) * $limit;

    // 検索条件の生成
    $where_sql = generate_search_conditions($wpdb);

    // デバッグ用にSQLとヒット件数を出力
    // $total_matches = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}usces_order WHERE $where_sql");
    // echo '<p><strong>SQL:</strong> WHERE <span id="generatedsql">' . esc_html($where_sql) . '</span> — ヒット件数: <span id="records-number">' . intval($total_matches) . '</span></p>';


    $debug_sql = "SELECT * FROM {$wpdb->prefix}usces_order WHERE $where_sql ORDER BY ID DESC LIMIT 100";
    $total_matches = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}usces_order WHERE $where_sql");
    echo '<p><strong>SQL:</strong> <span style="color:blue;">' . esc_html($debug_sql) . '</span> — ヒット件数: <span id="records-number">' . intval($total_matches) . '</span></p>';

    // ...受注データ取得・表示部分...

    // 受注データを取得時はprepareせずにそのままSQLを渡す
    $orders = $wpdb->get_results("
        SELECT ID, order_date, order_payment_name, order_item_total_price, order_status, mem_id, order_email, order_discount, order_note 
        FROM {$wpdb->prefix}usces_order
        WHERE $where_sql
        ORDER BY ID DESC
        LIMIT $limit OFFSET $offset
    ");

    // 総件数を取得
    $total_orders = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}usces_order
        WHERE $where_sql
    ");

    if (empty($orders)) {
        echo '<p>受注データが見つかりませんでした。</p>';
    } else {
        ///////////////////テーブルの生成start//////////////////////
        echo '<table id="orders-main-table" class="widefat fixed" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th>注文番号</th>';
        echo '<th>受注日</th>';
        //debug
        // echo '<th>デバッグ</th>';
        echo '<th>対応状況</th>';
        echo '<th>支払方法</th>';
        echo '<th>入金状況</th>';
        echo '<th>総合計金額(円)</th>';
        echo '<th>会員No.</th>';
        echo '<th>Eメール</th>';
        echo '<th>備考</th>';
        echo '<th>クーポン</th>';
        echo '<th>コントロール</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($orders as $order) {
            // 新たに order_status を解析
            $order_status = trim($order->order_status);
            $taio_status = '';
            $receipt_status = '';
            if (!empty($order_status)) {
                $parts = explode(',', $order_status);
                $filtered = [];
                foreach ($parts as $part) {
                    $p = trim($part);
                    if ($p !== 'adminorder' && $p !== '') {
                        $filtered[] = $p;
                    }
                }
                if (count($filtered) == 1) {
                    $receipt_status = $filtered[0];
                } elseif (count($filtered) >= 2) {
                    $taio_status = $filtered[0];
                    $receipt_status = $filtered[1];
                }
            } else {
                $receipt_status = 'noreceipt';
            }
            
            echo '<tr class="order_table_tr" id="order_' . esc_html($order->ID) . '">';
            echo '<td><a href="?page=welcart-order-admin-detail&order_id=' . esc_html($order->ID) . '">' . esc_html($order->ID) . '</a></td>';
            echo '<td>' . esc_html($order->order_date) . '</td>';
            // echo '<td>' . esc_html($order->order_status) . '</td>';
            // 対応状況表示

            if (!($taio_status) || $taio_status === "#none#") {
                $display_taio = "新規受付中";
            } elseif ($taio_status === "duringorder") {
                $display_taio = "取り寄せ中";
            } elseif ($taio_status === "cancel") {
                $display_taio = "キャンセル";
            } elseif ($taio_status === "completion") {
                $taio_style = ' style="color:green;"';
                $display_taio = "発送済み";
            }

            echo '<td' . $taio_style . '>' . esc_html($display_taio) . '</td>';
            echo '<td>' . esc_html($order->order_payment_name) . '</td>';
            // 入金状況の表示
            $receipt_style = '';
            $display_receipt = $receipt_status;
            if ($receipt_status === "noreceipt") {
                $display_receipt = "未入金";
                $receipt_style = ' style="color:red;"';
            } elseif ($receipt_status === "receipted") {
                $receipt_style = ' style="color:green;"';
                $display_receipt = "入金済み";
            } elseif ($receipt_status === "pending") {
                $display_receipt = "Pending";
            }
            echo '<td' . $receipt_style . '>' . esc_html($display_receipt) . '</td>';

            //割引適用後の合計金額
            $order_discount = $order->order_discount;
            $order_item_total_price = $order->order_item_total_price;
            $order_total_price = $order_item_total_price + $order_discount;

            echo '<td>' . esc_html(round($order_total_price)) . '</td>';
            echo '<td>' . esc_html($order->mem_id) . '</td>';
            echo '<td>' . esc_html($order->order_email) . '</td>';
            echo '<td>' . esc_html($order->order_note) . '</td>';

            // クーポン情報の取得
            $csod_coupon = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}usces_order_meta WHERE order_id = %d AND meta_key = %s",
                    $order->ID,
                    'csod_coupon'
                )
            );
            $row[] = $csod_coupon;
            echo '<td>' . esc_html($csod_coupon) . '</td>';


            $delete_url = wp_nonce_url(
                add_query_arg(array('delete_order' => $order->ID), admin_url('admin.php?page=welcart-order-admin')),
                'delete_order_' . $order->ID
            );
            echo '<td><a href="' . $delete_url . '" onclick="return confirm(\'本当に削除しますか？\');">削除</a></td>';
            echo '</tr>';
            $order_memo = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
                    $order->ID,
                    "order_memo"
                )
            );
            if (!empty($order_memo)) {
                echo '<tr><td colspan="11">管理者メモ：' . esc_html($order_memo) . '</td>';
                echo '</tr>';
            }
            $taio_style = '';
            $receipt_style = '';
        }
        echo '</tbody>';
        echo '</table>';

        // ページネーションの表示
        $total_pages = ceil($total_orders / $limit);
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            $base_url = add_query_arg(array_filter($_GET), admin_url('admin.php'));
            if ($paged > 1) {
                echo '<a class="prev-page" href="' . add_query_arg('paged', $paged - 1, $base_url) . '">&laquo; 前へ</a>';
            }
            $start = max(1, $paged - 5);
            $end = min($total_pages, $paged + 5);
            for ($i = $start; $i <= $end; $i++) {
                if ($i == $paged) {
                    echo '<span class="current-page">' . $i . '</span>';
                } else {
                    echo '<a class="page-number" href="' . add_query_arg('paged', $i, $base_url) . '">' . $i . '</a>';
                }
            }
            if ($paged < $total_pages) {
                echo '<a class="next-page" href="' . add_query_arg('paged', $paged + 1, $base_url) . '">次へ &raquo;</a>';
            }
            echo '</div></div>';
        }
    }
    echo '</div>';
    echo '<p>メモリ使用量 : ' . round(memory_get_peak_usage()/1048576, 1) . '</p>';
}

// 注文削除処理を行う関数
function custom_delete_order() {
    if (!isset($_GET['delete_order'])) {
        return;
    }
    global $wpdb;
    $order_id = intval($_GET['delete_order']);
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_order_' . $order_id)) {
        wp_die('不正なアクセスです。');
    }
    $result1 = $wpdb->delete($wpdb->prefix . 'usces_order', array('ID' => $order_id), array('%d'));
    $result2 = $wpdb->delete('vwp_usces_order_meta', array('order_id' => $order_id), array('%d'));
    wp_redirect(remove_query_arg(array('delete_order','_wpnonce'), add_query_arg('deleted', 1, admin_url('admin.php?page=welcart-order-admin'))));
    exit;
}
add_action('admin_init', 'custom_delete_order');

// 注文更新処理を行う関数を追加
function update_welcart_order() {
    if (!isset($_POST['update_order']) || !isset($_POST['order_id'])) {
        return;
    }

    if (!check_admin_referer('update_welcart_order')) {
        wp_die('不正なアクセスです。');
    }

    global $wpdb;
    $order_id = intval($_POST['order_id']);
    
    $new_receipt = sanitize_text_field($_POST['receipt_status']);
    if(empty($new_receipt)) {
        $new_receipt = 'noreceipt';
    }
    $new_taio = sanitize_text_field($_POST['order_taio']);
    $combined_status = !empty($new_taio) ? $new_taio . ',' . $new_receipt : $new_receipt;
    
    // アップデート!
    $update_data = array(
        'order_note' => sanitize_textarea_field($_POST['order_note']),
        'order_status' => $combined_status,
        'order_payment_name' => sanitize_text_field($_POST['order_payment_name']),
        'order_discount' => sanitize_text_field($_POST['order_discount']),
        'order_email' => sanitize_text_field($_POST['order_email'])
        //更新しない  'order_item_total_price' => sanitize_text_field($_POST['order_item_total_price'])
    );

    $wpdb->update(
        $wpdb->prefix . 'usces_order',
        $update_data,
        array('ID' => $order_id)
    );

    // 管理者メモの更新処理
    if (isset($_POST['order_memo'])) {
        $new_memo = sanitize_textarea_field($_POST['order_memo']);
        $wpdb->replace(
            'vwp_usces_order_meta',
            array(
                'order_id'   => $order_id,
                'meta_key'   => 'order_memo',
                'meta_value' => $new_memo,
            ),
            array('%d', '%s', '%s')
        );
    }

    // クーポンの更新処理（初期値がない場合でも上書きするため、存在チェックしてupdate/insertを分岐）
    if (isset($_POST['csod_coupon'])) {
        $new_coupon = sanitize_text_field($_POST['csod_coupon']);
        $meta_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
            $order_id, 'csod_coupon'
        ));
        if ($meta_exists) {
            $wpdb->update(
                'vwp_usces_order_meta',
                array('meta_value' => $new_coupon),
                array('order_id' => $order_id, 'meta_key' => 'csod_coupon'),
                array('%s'),
                array('%d','%s')
            );
        } else {
            $wpdb->insert(
                'vwp_usces_order_meta',
                array(
                    'order_id'   => $order_id,
                    'meta_key'   => 'csod_coupon',
                    'meta_value' => $new_coupon,
                ),
                array('%d','%s','%s')
            );
        }
    }

    wp_redirect(add_query_arg(array(
        'page' => 'welcart-order-admin-detail',
        'order_id' => $order_id,
        'updated' => 1
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'update_welcart_order');

// 詳細画面を表示する関数
// 20251128 送金額の表示方法の変更
function custom_show_welcart_order_detail() {
    global $wpdb;
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        echo '<div class="wrap"><h2>注文詳細</h2><p>注文IDが指定されていません。</p></div>';
        return;
    }

    // 更新メッセージの表示
    if (isset($_GET['updated'])) {
        echo '<div class="updated"><p>注文情報が更新されました。</p></div>';
    }

    // 注文情報を取得
    $order = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}usces_order
        WHERE ID = %d
    ", $order_id));

    if (!$order) {
        echo '<div class="wrap"><h2>注文詳細</h2><p>注文が見つかりませんでした。</p></div>';
        return;
    }

    //対応状況と入金情報の処理
    $order_status_raw = trim($order->order_status);
    $taio_status = '';
    $receipt_status = '';
    if (!empty($order_status_raw)) {
        // カンマ区切りの場合は分割して"adminorder"を除去
        $parts = explode(',', $order_status_raw);
        $filtered = array_filter(array_map('trim', $parts), function($v){
            return $v !== 'adminorder';
        });
        $filtered = array_values($filtered);
        if (count($filtered) == 1) {
            // 1つのみなら入金状況
            $receipt_status = $filtered[0];
        } elseif (count($filtered) >= 2) {
            // 2つ以上なら先頭を対応状況、2番目を入金状況
            $taio_status = $filtered[0];
            $receipt_status = $filtered[1];
        }
    } else {
        $receipt_status = 'noreceipt';
    }
    if (empty($taio_status)) {
        $taio_status = "#none#";
    }

    // 管理者メモの取得（vwp_usces_order_metaテーブル）
    $admin_memo = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
            $order_id,
            'order_memo'
        )
    );

    // クーポン値の取得
    $csod_coupon = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
            $order_id,
            'csod_coupon'
        )
    );

    //　割引の適用
    $order_discount = $order->order_discount;
    $order_item_total_price = $order->order_item_total_price;
    $order_total_price = $order_item_total_price + $order_discount;

    // 決済IDの取得
    $settlement_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
            $order_id,
            'settlement_id'
        )
    );


    // 購入内容の取得
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}usces_ordercart
        WHERE order_id = %d
    ", $order_id));

    // 注文者名の取得
    $order_name1 = $order->order_name1;

    //詳細表示タイトル
    echo '<div class="wrap">';
    echo '<h2>注文詳細</h2>';

    //デバッグ用表示
    echo $csod_coupon;
    /*
    echo '<p>';
    echo '<span>入金・対応ステータスの値(デバッグ用)</span><br>';
    echo 'order_status_row:' . $order_status_raw . '</br>';
    echo 'order_status:' . $order->order_status . '</br>';
    echo 'receipt_status:' . $receipt_status . '</br>';
    echo 'taio_status:' . $taio_status . '</br>';
    echo '</p>';
    */

    echo '<p>';
    echo '<button id="woca-open-email-modal-btn" class="button">メール送信</button>';
    echo '</p>';


    echo '<form method="post" action="">';
    wp_nonce_field('update_welcart_order');
    echo '<input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">';
    echo '<p class="submit-buttons-wrap">';
    echo '<input type="submit" name="update_order" class="button button-primary" value="設定を更新">';
    echo ' <a href="' . admin_url('admin.php?page=welcart-order-admin') . '" class="button">戻る</a>';
    echo '</p>';
    echo '<p class="submit-buttons-wrap">値を変更した場合は必ず最後に「設定を更新」ボタンを押してください。</p>';
    echo '<table class="widefat fixed welcart-order-detail order-detail-table">';
    echo '<thead><tr><th class="title">項目</th><th class="content">詳細</th></tr></thead>';
    echo '<tbody>';
    echo '<tr><td>管理者メモ</td><td><textarea name="order_memo" rows="5" style="width:100%">' . esc_textarea($admin_memo) . '</textarea></td></tr>';
    echo '<tr><td>注文ID</td><td>' . esc_html($order->ID) . '</td></tr>';
    echo '<tr><td>注文日</td><td>' . esc_html($order->order_date) . '</td></tr>';
    echo '<tr><td>注文者ID</td><td>' . esc_html($order->mem_id) . '</td></tr>';
    echo '<tr><td>メールアドレス</td><td><input type="text" name="order_email" value="' . esc_attr($order->order_email) . '" style="width:100%"></td></tr>';
    echo '<tr><td>支払い方法</td><td>';
    $payment_options = [
        "クレジットカード（PAYPAL）",
        "銀行振込み（ゆうちょ銀行）",
        "銀行振込（PayPay銀行）",
        "微信（WeChat）",
        "银行汇款（中国工商银行）",
        "無料（FREE CHARGE）",
        "GMOあおぞらネット銀行"
    ];
    echo '<select name="order_payment_name" style="width:100%">';
    echo '<option value=""></option>';
    foreach ($payment_options as $option) {
        echo '<option value="' . esc_attr($option) . '" ' . selected($order->order_payment_name, $option, false) . '>' . esc_html($option) . '</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    
    // $order_status = empty($order->order_status) ? "noreceipt" : $order->order_status;
    $receipt_map = [
        "noreceipt" => "未入金",
        "receipted" => "入金済み",
        "pending"   => "Pending"
    ];
    $receipt_status_options = [
        "noreceipt",
        "receipted",
        "pending"
    ];
    echo '<tr><td>入金状況</td><td>';
    echo '<select name="receipt_status" style="width:100%">';
    echo '<option value=""></option>';
    foreach ($receipt_status_options as $opt) {
         // $opt の中にカンマがある場合は、取得したreceipt_statusと比較
         $selected_value = (strpos($opt, ',') !== false) ? $receipt_status : $opt;
         $label = isset($receipt_map[$opt]) ? $receipt_map[$opt] : $opt;
         // なお、selectの値自体は変更後の保存用に元の $opt を利用 (必要に応じて別途処理してください)
         echo '<option value="' . esc_attr($opt) . '" ' . selected($receipt_status, $selected_value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</td></tr>';

    // 対応状況（taio_status）の表示
    $taio_map = [
        "#none#"      => "新規受付中",
        "cancel"      => "キャンセル",
    ];

    echo '<tr><td>対応状況</td><td>';
    echo '<select name="order_taio" style="width:100%">';
    echo '<option value=""></option>';
    foreach ($taio_map as $value => $label) {
        echo '<option value="' . esc_attr($value) . '" ' . selected($taio_status, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</td></tr>';
    echo '<tr><td>備考</td><td><textarea name="order_note" rows="3" style="width:100%">' . esc_textarea($order->order_note) . '</textarea></td></tr>';
    echo '</tbody></table>';

    //　カスタムオーダーフィールド
    echo '<h3>カスタムオーダーフィールド</h3>';
    echo '<table class="widefat fixed castum-order-table welcart-order-detail">';
    echo '<tr><th class="title">クーポン</th><td><input type="text" name="csod_coupon" value="' . esc_attr($csod_coupon) . '" style="width:100%"></td></tr>';
    echo '<tr><th class="title">決済ID（Paypalの場合)</th><td>' . esc_attr($settlement_id) .  '</td></tr>';
    echo '</table>';


    // 商品リストの表示
    if (!empty($items)) {
        echo '<table class="widefat fixed  welcart-order-detail">';
        echo '<thead><tr><th class="title">商品名</th><th>単価</th><th>金額</th></tr></thead><tbody>';

        // 商品の合計を計算する
        $subtotal_price = 0;
        foreach ($items as $item) {
            echo '<tr>';
            echo '<td class="title">' . esc_html($item->item_name) . '</td>';
            echo '<td>' . esc_html(number_format(round($item->price))) . '</td>';
            echo '<td>' . esc_html(number_format(round($item->price * $item->quantity))) . '</td>';
            echo '</tr>';
            $subtotal_price += $item->price * $item->quantity;
        }
        
        echo '<tr><td colspan="2">小計</td><td id="subtotal_price"><input type="hidden" name="subtotal_price" value="' . esc_attr(round($subtotal_price)) . '">' . esc_attr(number_format(round($subtotal_price))) . '</td></tr>';
        echo '<tr><td colspan="2">クーポン割引</td><td id="coupon_amount"><input type="text" name="order_discount" value="' . esc_attr(round($order_discount)) . '" style="width:100%"><span>※割引は-(マイナス)で入力します</span></td></tr>';
        echo '<tr><td colspan="2">総合計金額</td><td id="total_price" style="background-color: black;color: #efefef;line-height: 1.6;">';
    //    echo '<input type="text" id="overall_total_input" name="order_item_total_price" value="' . esc_html(round($order_total_price)) . '" data-base-total="' . esc_html(round($subtotal_price)) . '" style="width:70%">';
        echo '<span id="overall_total_input" name="order_item_total_price" data-base-total="' . esc_html(round($subtotal_price)) . '" style="width:70%; margin-right:2rem; font-stile:bold; font-size:1.6rem;">' . esc_html(number_format(round($order_total_price))) . '</span>';
        echo '<button type="button" id="recalculate_btn" style="width:28%">再計算</button>';
        echo '</td></tr>';
        echo '</tbody></table>';
    }

    echo '<p class="submit">';
    echo '<input type="submit" name="update_order" class="button button-primary" value="設定を更新">';
    echo ' <a href="' . admin_url('admin.php?page=welcart-order-admin') . '" class="button">戻る</a>';
    echo '</p>';
    echo '</form>';
    woca_render_email_modal($order);
    echo '</div>';
}

/**
 * メール送信機能
 *
 * 留意点:
 * - テンプレート、メール送信はハードコードしています。
 * - モーダル、wp_mail、AJAXハンドラを使って送信しています。
 */

/**
 * メールのテンプレート
 * ・入金御礼メール
 * ・入金督促メール
 */
function woca_get_default_email_templates() {
    return array(
        'templates' => array(
            'payment_confirmation' => array(
                'title'   => '入金御礼メール（デフォルト）',
                'subject' => '[注文番号 %ORDER_ID%] ご入金ありがとうございます',
                'body' => trim(<<<'EOT'
%CUSTOMER_NAME%様
この度は「かべネコ VPN-Cats On The Wall 」 をご利用下さいまして誠に有難うございます。

また，早々にご入金くださり，心から御礼申し上げます。確認させていただきました。

ご利用に際して，問題等ございましたら，些細なことでも結構ですので，お気軽にお問い合わせ頂ければ幸いです。

この度のご購入に重ねて感謝申し上げます。

【ご注文内容】
******************************************************
注文番号 : %ORDER_ID% 
注文日時 : %ORDER_DATE% 
お申し込みプラン :
------------------------------------------------------------------
%ORDER_ITEMS%
=============================================
商品合計 : %ORDER_ITEM_TOTAL%
キャンペーン割引 : %ORDER_DISCOUNT%
------------------------------------------------------------------
お支払い金額 : %ORDER_TOTAL%
------------------------------------------------------------------
 (通貨 : 円)

【お支払方法】
******************************************************
%ORDER_PAYMENT_NAME%
%ORDER_SET_ID% 
******************************************************


かべネコVPN
=============================================
かべネコ - Cats On The Wall 
株式会社エフネット
〒 160-0023
東京都新宿区西新宿三丁目3番13号 西新宿水間ビル6階
お問合せ info@kabeneko.net
https://kabeneko.biz
=============================================
EOT
                ),
            ),
            'payment_remind' => array(
                'title'   => '入金督促メール',
                'subject' => '入金のお願い %ORDER_ID%',
                'body' => trim(<<<'EOT'
%CUSTOMER_NAME%様
かべネコVPNです。当サービスをご利用下さり，心から感謝申し上げます。
ご利用いただける残りが、[５日]以下になりましたので，お知らせいたします。

※本メールは、残りポイントが５０（５日分相当）以下に、または、ご利用期限が後５日以下になったユーザー様にお送りしています。

期限を過ぎますとポイントは失効いたします。

ご利用期限および保有ポイントを「ユーザーログイン」後にご確認頂けます。
【ユーザーログインページ】
https://wallcats.net/usces-member/

■延長のお手続き
期限延長手続きは、いつでも可能です。かべネコのサイトからご希望のサービスをカートに入れてお申込み頂きますと自動的に期間が延長されます。また，ご購入にあたりましては,このメールの宛先にあるアドレスと、登録時に設定いただいたパスワードをご利用下さい。

【カートで申し込めるプラン一覧】
https://kabeneko.biz/price_table/


■パスワードをお忘れの場合
パスワードをお忘れの場合は，下記から再設定できます。

※ご注意:パスワードを変更しますと,VPN接続用のパスワードも変更する必要がございます。
【パスワード再設定ページ】
https://wallcats.net/usces-member/?page=lostmemberpassword

ご不明な点がございましたら，下記フォームからお気軽に尋ねて頂ければ幸いです。

【お問合せフォーム】
https://kabeneko.biz/ask/


今後ともかべネコVPNをよろしく願いいたします。


かべネコVPN
=============================================
かべネコ - Cats On The Wall 
株式会社エフネット
〒 160-0023
東京都新宿区西新宿三丁目3番13号 西新宿水間ビル6階
お問合せ info@kabeneko.net
https://kabeneko.biz
=============================================
EOT
                ),
            )
        )
    );
}

/**
 * メールテンプレートの扱い
 *
 * Structure:
 * array(
 *   'templates' => array( 'slug' => array('title'=>..., 'subject'=>..., 'body'=>...), ... )
 * )
 */
function woca_get_email_templates() {
    return woca_get_default_email_templates();
}

/*
カスタマー名はorder_mailを使用する
*/
function woca_get_order_customer_name( $order_id, $order_obj = null ) {
    global $wpdb;

    if ( $order_obj && ! empty( $order_obj->order_email ) ) {
        return trim( $order_obj->order_email );
    }

    $order_email = $wpdb->get_var( $wpdb->prepare(
        "SELECT order_email FROM {$wpdb->prefix}usces_order WHERE ID = %d LIMIT 1",
        $order_id
    ) );

    return $order_email ? trim( $order_email ) : '';
}

/**
 * Get order items text (plain text).
 */
function woca_get_order_items_text($order_id) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT item_name, price, quantity FROM {$wpdb->prefix}usces_ordercart WHERE order_id = %d",
        $order_id
    ) );
    if ( empty($rows) ) return '';

    $lines = array();
    foreach ( $rows as $r ) {
        // $lines[] = sprintf("%s × %d   単価: %s   金額: %s", $r->item_name, intval($r->quantity), round($r->price), round($r->price * $r->quantity));
        $lines[] = sprintf("%s × %d   ", $r->item_name, intval($r->quantity), round($r->price), round($r->price * $r->quantity));
    }
    return implode("\n", $lines);
}

/**
 * Replace placeholders in templates for a given order.
 */
function woca_render_template_for_order($template_text, $order_id, $order_obj = null) {
    global $wpdb;
    if ( ! $order_obj ) {
        $order_obj = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}usces_order WHERE ID = %d", $order_id ) );
    }

    $order_discount = '';
    $order_total = '';
    $order_date = '';
    if ( $order_obj ) {

        $order_item_total = isset($order_obj->order_item_total_price) ? round($order_obj->order_item_total_price) : 0;
        $order_discount   = isset($order_obj->order_discount) ? round($order_obj->order_discount) : 0;
        $order_total = $item_total + $discount; // 割引はマイナス値なのでOK
        $order_date = isset($order_obj->order_date) ? $order_obj->order_date : '';
        $order_payment_name = isset($order_obj->order_payment_name) ? $order_obj->order_payment_name : '';
    }

    // 決済IDの取得
    $settlement_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM vwp_usces_order_meta WHERE order_id = %d AND meta_key = %s",
            $order_id,
            'settlement_id'
        )
    );
    $order_settlement_id = isset($settlement_id) ? 'カート用受注識別コード ; ' . $settlement_id : '';

    $replacements = array(
        '%ORDER_ID%'      => $order_id,
        '%CUSTOMER_NAME%' => woca_get_order_customer_name($order_id, $order_obj),
        '%ORDER_ITEM_TOTAL%' => $order_item_total,
        '%ORDER_TOTAL%'   => $order_total,
        '%ORDER_ITEMS%'   => woca_get_order_items_text($order_id),
        '%ORDER_DATE%'    => $order_date,
        '%ORDER_URL%'     => admin_url('admin.php?page=welcart-order-admin-detail&order_id=' . intval($order_id)),
        '%ORDER_DISCOUNT%'=> $order_discount,
        '%ORDER_PAYMENT_NAME%' => $order_payment_name,
        '%ORDER_SET_ID%'  => $order_settlement_id,
//        '%ORDER_NAME1%'   => $order_name1,
    );

    return str_replace(array_keys($replacements), array_values($replacements), $template_text);
}

/**
 * メール送信フォームのモーダルを表示する
 */
function woca_render_email_modal($order) {
    if ( ! $order || ! isset($order->ID) ) return;

    $order_id = intval($order->ID);
    $templates_struct = woca_get_email_templates();
    $templates = isset($templates_struct['templates']) && is_array($templates_struct['templates']) ? $templates_struct['templates'] : array();

    // Note: nonce is generated here for the modal; it's safe because it's admin-only output.
    $nonce = wp_create_nonce('woca_send_email');
    ?>
    <!-- WOCA: Email modal -->
    <div id="woca-email-modal" style="display:none;">
        <div id="woca-email-modal-overlay"></div>
        <div id="woca-email-modal-box" role="dialog" aria-modal="true" aria-labelledby="woca-email-modal-title">
            <h2 id="woca-email-modal-title">メール送信</h2>
            <div class="woca-email-field">
                <label>テンプレート:
                    <select id="woca-template-select">
                        <?php foreach ($templates as $slug => $tpl) : ?>
                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($tpl['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="woca-email-field">
                <label>送信先メールアドレス:<br>
                    <input type="email" id="woca-email-to" value="<?php echo esc_attr($order->order_email); ?>" style="width:100%">
                </label>
            </div>
            <div class="woca-email-field">
                <label>件名:<br>
                    <input type="text" id="woca-email-subject" style="width:100%">
                </label>
            </div>
            <div class="woca-email-field">
                <label>本文:<br>
                    <textarea id="woca-email-body" rows="10" style="width:100%"></textarea>
                </label>
            </div>
            <div class="woca-email-actions">
                <button class="button button-primary" id="woca-email-send-btn">送信</button>
                <button class="button" id="woca-email-cancel-btn">キャンセル</button>
            </div>
        </div>
    </div>

    <script type="text/javascript">
    // Expose template data and ajax params to JS.
    window.woca_email_templates = <?php echo wp_json_encode($templates); ?>;
    window.woca_email_ajax = {
        ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo $nonce; ?>',
        order_id: <?php echo $order_id; ?>
    };
    </script>
    <?php
}

/**
 * AJAX handler: send email from admin.
 */


 add_action('wp_ajax_woca_send_order_email', 'woca_send_order_email');
 function woca_send_order_email() {
     // nonce
     if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'woca_send_email') ) {
         wp_send_json_error( array('message' => '不正なリクエスト（nonce）。') );
     }
 
     // capability (filterable)
     $capability = apply_filters('woca_email_send_capability', 'edit_posts');
     if ( ! current_user_can($capability) ) {
         wp_send_json_error( array('message' => '送信権限がありません。') );
     }
 
     $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
     $to = isset($_POST['to']) ? sanitize_email($_POST['to']) : '';
     $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
     $body = isset($_POST['body']) ? wp_strip_all_tags($_POST['body']) : '';
 
     if ( ! $order_id || ! is_email($to) ) {
         wp_send_json_error( array('message' => '宛先メールアドレスが不正、または注文IDが指定されていません。') );
     }
 
     // load order
     global $wpdb;
     $order = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}usces_order WHERE ID = %d", $order_id) );
 
     // template replacements
     $subject = woca_render_template_for_order($subject, $order_id, $order);
     $body = woca_render_template_for_order($body, $order_id, $order);
 
     // ---------------------------
     // From / Reply-To をハードコードしています
     // デフォルトは管理者メールとサイト名を使う
     
     $from_email = 'no-reply@kabeneko.biz';
     $from_name  = 'かべネコVPN';
 
     // ヘッダ作成（安全のためサニタイズ）
     $safe_from_name  = wp_strip_all_tags( $from_name );
     $safe_from_email = sanitize_email( $from_email );
 
     $headers = array();
     $headers[] = 'From: ' . $safe_from_name . ' <' . $safe_from_email . '>';
     $headers[] = 'Reply-To: ' . $safe_from_email;
 
     // ---------------------------
     // HTML メール化（改行を <br> に変換して送信）
     $html_body = '<!doctype html><html><head><meta charset="utf-8"></head><body>';
     $html_body .= nl2br( esc_html( $body ) );
     $html_body .= '</body></html>';
 
     // content-type フィルタ用コールバックを安全に定義
     if ( ! function_exists( 'woca_set_html_mail_content_type' ) ) {
         function woca_set_html_mail_content_type() {
             return 'text/html; charset=UTF-8';
         }
     }
 
     // 一時的に content-type を HTML に変更して送信
     add_filter( 'wp_mail_content_type', 'woca_set_html_mail_content_type' );
     $sent = wp_mail( $to, $subject, $html_body, $headers );
     remove_filter( 'wp_mail_content_type', 'woca_set_html_mail_content_type' );
 
     if ( $sent ) {
         wp_send_json_success( array('message' => 'メールを送信しました。') );
     } else {
         wp_send_json_error( array('message' => 'メール送信に失敗しました。サーバのメール設定を確認してください。') );
     }
 }

/**
 * Log wp_mail failures for debugging.
 */
add_action('wp_mail_failed', function($wp_error){
    error_log("woca: wp_mail_failed: " . print_r($wp_error->get_error_messages(), true));
});

/**
 * Admin enqueue: (optional) localize templates to existing admin script.
 * If your admin JS enqueues 'welcart-order-admin-scripts', this will expose templates there too.
 * This is kept optional: it only localizes data for that script handle.
 */
add_action('admin_enqueue_scripts', function() {
    $templates_struct = woca_get_email_templates();
    $templates = isset($templates_struct['templates']) ? $templates_struct['templates'] : array();

    // Only localize if the script is registered/enqueued.
    if ( wp_script_is('welcart-order-admin-scripts', 'enqueued') || wp_script_is('welcart-order-admin-scripts', 'registered') ) {
        wp_localize_script('welcart-order-admin-scripts', 'woca_localized', array(
            'templates' => $templates,
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('woca_send_email'),
        ));
    }
}, 25);

// WordPressの管理画面にメニューを追加
add_action('admin_menu', function() {
    add_menu_page(
        'Welcart Order Admin',         // ページタイトル
        'Welcart 受注管理',            // メニュータイトル
        'manage_options',              // 権限
        'welcart-order-admin',         // スラッグ
        'custom_show_welcart_orders',  // 表示する関数
        'dashicons-list-view',         // アイコン
        100                            // 表示位置
    );

    // 注文詳細画面
    add_submenu_page(
        null,                               // 親メニューを表示しない
        'Welcart Order Detail',             // ページタイトル
        'Welcart Order Detail',             // メニュータイトル(表示しない)
        'manage_options',                   // 権限
        'welcart-order-admin-detail',       // サブメニューのスラッグ
        'custom_show_welcart_order_detail'  // 表示関数
    );
}, 20); // フックの優先度を20に設定