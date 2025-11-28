/* werlcart--admin-scripts.js version: 1.06*/

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
			var newTotal = baseTotal + couponVal;
			overallInput.text(newTotal.toLocaleString());
		});
	}

	// CSV出力フォームの切替
	$("#csv_export_btn").on("click", function(){
		$("#csv_export_container").toggle();
	});

	
			// open modal when 「メール送信」ボタンが clicked
			$(document).on('click', '#woca-open-email-modal-btn', function(e){
				e.preventDefault();
				// init: get templates from localized or window.woca_email_templates
				var templates = window.woca_localized && window.woca_localized.templates ? window.woca_localized.templates : window.woca_email_templates || {};
				// populate select if empty
				var $select = $('#woca-template-select');
				if ($select.length && $select.children().length === 0) {
					$.each(templates, function(slug, tpl){
						$select.append($('<option>').val(slug).text(tpl.title));
					});
				}
				// set fields to selected template
				var first = $select.children('option').first().val();
				if ( first ) {
					$select.val(first).trigger('change');
				}
				$('#woca-email-modal').show();
			});
	
			// close modal
			$(document).on('click', '#woca-email-cancel-btn, #woca-email-modal-overlay', function(e){
				e.preventDefault();
				$('#woca-email-modal').hide();
			});
	
			// template change -> populate subject/body with replacements for current order (placeholders remain)
			$(document).on('change', '#woca-template-select', function(){
				var slug = $(this).val();
				var templates = window.woca_localized && window.woca_localized.templates ? window.woca_localized.templates : window.woca_email_templates || {};
				if ( templates[slug] ) {
					$('#woca-email-subject').val( templates[slug].subject );
					$('#woca-email-body').val( templates[slug].body );
				}
			});
	
			// send button click
			$(document).on('click', '#woca-email-send-btn', function(e){
				e.preventDefault();
				var to = $('#woca-email-to').val();
				var subject = $('#woca-email-subject').val();
				var body = $('#woca-email-body').val();
				var order_id = (window.woca_email_ajax && window.woca_email_ajax.order_id) ? window.woca_email_ajax.order_id : (window.woca_localized && window.woca_localized.order_id ? window.woca_localized.order_id : 0);
				var nonce = (window.woca_localized && window.woca_localized.nonce) ? window.woca_localized.nonce : (window.woca_email_ajax && window.woca_email_ajax.nonce ? window.woca_email_ajax.nonce : '');
	
				if (!to || to.indexOf('@') === -1) {
					alert('送信先メールアドレスが不正です。');
					return;
				}
				if (!confirm('送信してよろしいですか？')) return;
	
				var data = {
					action: 'woca_send_order_email',
					nonce: nonce,
					order_id: order_id,
					to: to,
					subject: subject,
					body: body
				};
				var $btn = $(this);
				$btn.prop('disabled', true).text('送信中…');
				$.post(window.woca_localized.ajax_url, data, function(res){
					if (res && res.success) {
						alert(res.data.message || '送信しました。');
						$('#woca-email-modal').hide();
					} else {
						var msg = (res && res.data && res.data.message) ? res.data.message : '送信に失敗しました。';
						alert(msg);
					}
				}).fail(function(){
					alert('送信に失敗しました（ネットワークエラー）。');
				}).always(function(){
					$btn.prop('disabled', false).text('送信');
				});
			});


});
