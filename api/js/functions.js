$(document).ready(function(){
	
	//---------------------------
	// Ajax Form
	//---------------------------	
    
    if($("#contactForm").length){

		$("#contactForm").on("submit", function(){
		    var ContactForm = $(this),
		    	errors = 0,
		        loader = $("#loader"),
		        result = $("#result");

		    if (ContactForm.data("submitting")) { return false; }
		        
		    loader.fadeIn();
		    result.find(".fail, .success").hide();
		    
		    ContactForm.find(".required").each(function(){     
				var id = $(this).attr("id");
		        if($(this).hasClass("email")){
		            errors += $(this).validateEmail();
		        }
				if($(this).hasClass("checkbox")){
		            errors += $(this).validateCheckbox();
		        }
				if($(this).hasClass("radio")){
		            errors += $(this).validateRadio();
		        }else if($(this).hasClass("digits")){
		            errors += $(this).validateDigits();
		        }else if($(this).attr("id") == "captcha"){
			        errors += $(this).validateCaptcha();
			    }else{
		            //Must contain at least 3 characters
		            errors += $(this).validateLength(3);
		        }
		    });
		    //If there are no errors, send the form
		    if(errors === 0){
			ContactForm.data("submitting", true);
			$("#reservation_submit_btn").prop("disabled", true).addClass("is-saving");
			ContactForm[0].submit();
		    }else{
		    	// else, nudge the incorrect fields
		    	ContactForm.find(".notRight").each(function(){
		    		$(this).nudge();
		    	});
		    }
		    loader.fadeOut();     
	        return false;    
	    });
	
	    // shows/clears this field's inline error message, if it has one
	    function setFieldMsg($field, show) {
	    	var $msg = $field.closest(".field").find(".field-error");
	    	if ($msg.length) { $msg.text(show ? ($field.attr("data-msg") || "") : ""); }
	    }

	    //Content length validation
	    $.fn.validateLength = function(l){
	        if( this.val().length < l || this.val() == this.attr("placeholder") ) {
	        	this.addClass("notRight");
	        	setFieldMsg(this, true);
	        	return 1;
	        } else {
	            this.removeClass('error notRight');
	            setFieldMsg(this, false);
	            return 0;
	        }
	    };

		//email validation
		$.fn.validateEmail = function(){
			var filter = /^[a-zA-Z0-9]+[a-zA-Z0-9_.-]+[a-zA-Z0-9_-]+@[a-zA-Z0-9]+[a-zA-Z0-9.-]+[a-zA-Z0-9]+.[a-z]{2,4}$/;
			if(filter.test(this.val())){
				this.removeClass('error notRight');
				setFieldMsg(this, false);
				return 0;
			}else{
				this.addClass("notRight");
				setFieldMsg(this, true);
				return 1;
			}
		}
		//checkbox validation
		$.fn.validateCheckbox = function(){
			var $msg = $('#terms-error');
			if(this.is(':checked')){
				$('.checktext').removeClass("error notRight");
				$msg.text("");
				return 0;
			}else{
				$('.checktext').addClass("notRight error");
				$msg.text(this.attr("data-msg") || "");
				return 1;
			}
		}
		// picking a time clears the error marking of the whole time grid
		$(document).on("change", "input[name='reservation_time']", function(){
			$(".radiotext").removeClass("error notRight");
		});
		// ticking the box clears the hint
		$(document).on("change", "#terms", function(){
			if (this.checked) { $(".checktext").removeClass("error notRight"); $("#terms-error").text(""); }
		});
		//radio button validation
		$.fn.validateRadio = function(){
			if( $("#timefield input").is(':checked') ){
				$('.radiotext').removeClass('error notRight');
				return 0;
			}else{
				$('.radiotext').addClass("notRight");
				return 1;
			}
		}
		//digits validation
		$.fn.validateDigits = function(){
			var filter = /^[0-9]+$/;
			if(filter.test(this.val())){
				this.removeClass('error notRight');
				return 0;
			}else{
				this.addClass("notRight");
				return 1;
			}
		}
		
	    // Captcha validation
	    $.fn.validateCaptcha = function(){
	    	var field1 = parseInt($("#captchaField1").text()),
	    		operator = ( $("#captchaField2").text() == "+" ) ? true : false;
	    		field3 = parseInt($("#captchaField3").text()),
	    		correct = operator ? field1+field3 : field1-field3;   
	        if(this.val() != correct) {
	        	this.addClass("notRight");
	        	return 1;
	        } else {
	            this.removeClass('error notRight');
	            return 0;
	        }
	    };
		
	    // Nudge effect
		$.fn.nudge = function(){
			$(this).animate({
				'right' : -4
			}, 40, function(){
				$(this).animate({
					'right' : 4
				}, 40, function(){
					$(this).animate({
						'right' : -4
					}, 40, function(){
						$(this).animate({
							'right' : 4
						}, 40, function(){
							$(this).animate({
								'right' : -4
							}, 40, function(){
								$(this).animate({
									'right' : 0
								}, 40, function(){
									$(this).delay(80).removeClass('notRight').addClass("error");
								});
							});
						});
					});
				});
			});
		}
			
	}	


});