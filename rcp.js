jQuery(document).ready(function($){ 
	$('#new_apikey').click(function(e) {
		e.preventDefault();
		$.ajax({
			type: 		'POST',
			dataType: 	'json',
			url:  	 	ajaxurl,
			data:       {action: 'generate_new_apikey'}, 
			success: function(data) {
					$('#apikey').val(data.key);
			}			
		});
	});
});