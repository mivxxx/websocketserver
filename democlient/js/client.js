var socket = null;

function log(msg) {
  return $('#log').append("" + msg + "<hr />");
};

function connect() {
	var address = $('#address').val();
	var port = $('#port').val();
    var serverUrl = 'ws://' + address + ':' + port;
    if (window.MozWebSocket) {
		socket = new MozWebSocket(serverUrl);
    } else if (window.WebSocket) {
		socket = new WebSocket(serverUrl);
    }

    socket.onopen = function(msg) {
		return $('#status').removeClass().addClass('online').html('connected');
    };
    socket.onclose = function(msg) {
		return $('#status').removeClass().addClass('offline').html('disconnected');
    };

    socket.onmessage = function(msg) {
      log(msg.data);
    };
}

$(document).ready(function() {
    $('#connect').click(function() {
		connect();
    });

    $('#send').click(function() {
		socket.send($('#message').val());
    });
	
	var select = $('#predefined-messages');
	var optionsHtmlTemplate = '<option value=""></option>';
	var optionsHtml = '';
	var data = [];
	optionsHtml += optionsHtmlTemplate;
	$('div.message-templates').children('div').each(function(index, value) {
		var id = value.getAttribute('id');
		var message = value.innerHTML;
		data.push({id: id, message: message});
		
		optionsHtml += optionsHtmlTemplate;
	});
	select.html(optionsHtml);
	select.children('option').each(function(index, value) {
		if (index == 0) {
			return;
		}
		value.innerHTML = data[index - 1].id;
		value.setAttribute('value', data[index - 1].message);
	});	

	select.change(function() {
		$('#message').val(select.val());
	});
	
});
