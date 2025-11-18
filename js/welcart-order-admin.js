/* werlcart-order-admin-scripts.js 
	version: 1.0.0
*/
jQuery(document).ready(function($) {
	// 統一されたフィールド切替関数（php出力のセレクトボックスを単に表示/非表示に切替）
	function toggleFieldDisplay(fieldSelector, inputName, operatorName, paymentSelectId, statusSelectId, taioSelectId) {
		$(fieldSelector).on('change', function() {
			var fieldVal = $(this).val();
			if (fieldVal === "order_payment_name") {
				$("input.search-text[name='" + inputName + "']").val('').css("display", "none");
				$("select[name='" + operatorName + "']").css("display", "none");
				$("#" + paymentSelectId).css("display", "inline-block");
				$("#" + statusSelectId).css("display", "none").val('');
				$("#" + taioSelectId).css("display", "none").val('');
			} else if (fieldVal === "order_status") {
				// 入金状況用セレクトを表示
				$("input.search-text[name='" + inputName + "']").css("display", "none");
				$("select[name='" + operatorName + "']").css("display", "none");
				$("#" + paymentSelectId).css("display", "none").val('');
				$("#" + statusSelectId).css("display", "inline-block");
				$("#" + taioSelectId).css("display", "none").val('');
			} else if (fieldVal === "order_taio") {
				// 対応状況用セレクトを表示
				$("input.search-text[name='" + inputName + "']").css("display", "none");
				$("select[name='" + operatorName + "']").css("display", "none");
				$("#" + paymentSelectId).css("display", "none").val('');
				$("#" + statusSelectId).css("display", "none");
				$("#" + taioSelectId).css("display", "inline-block").val('');
			} else {
				$("input.search-text[name='" + inputName + "']").css("display", "inline-block");
				$("select[name='" + operatorName + "']").css("display", "inline-block");
				$("#" + paymentSelectId).css("display", "none").val('');
				$("#" + statusSelectId).css("display", "none").val('');
				$("#" + taioSelectId).css("display", "none").val('');
			}
		}).trigger('change');
	}

	// 各フィールドに対して適用（php出力されたセレクトボックスを利用）
	toggleFieldDisplay('#field1', 'value1', 'operator1', 'payment_method_select1', 'order_status_select1', 'taio_status_select1');
	toggleFieldDisplay('#search_field2', 'search_value2', 'operator2', 'payment_method_select2', 'order_status_select2', 'taio_status_select2');

	// クリア処理
	$("#clear_search_form").on("click", function() {
		window.location.href = "admin.php?page=welcart-order-admin";
	});

	// 再計算ボタンの処理
	var recalcBtn = $("#recalculate_btn");
	if(recalcBtn.length) {
		recalcBtn.on("click", function(){
			var overallInput = $("#overall_total_input");
			var baseTotal = parseFloat(overallInput.data("base-total")) || 0;
			var couponVal = parseFloat($("#coupon_amount input").val()) || 0;
			overallInput.val(baseTotal + couponVal);
		});
	}

	// CSV出力フォームの切替
	$("#csv_export_btn").on("click", function(){
		$("#csv_export_container").toggle();
	});
});
