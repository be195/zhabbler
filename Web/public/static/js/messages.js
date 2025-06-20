var current_user = null;
$(document).ready(function(){
    if(connection != null){
        if(connection == true){
            $(".alert-msgs-con").remove();
            getConvos();
        }else{
            $(".alert-msgs-con").show(0);
        }
        $("#MessagesLoader").remove();
    }
    $(document).on("input", ".main_messages_write_message_textarea textarea", function(){
        if($(window).width() > 980){
            this.setAttribute("style", "height:" + (this.scrollHeight) + "px;overflow-y:hidden;");
            this.style.height = 0;
            this.style.height = (this.scrollHeight) + "px";
            let npx = $(".main_messages_write_message").height() + 51;
            if($(".navbar_top").length > 0){
                npx += 56;
            }
            $("#MessagesList").css("height", `calc(100vh - ${npx}px)`);
            document.getElementById("MessagesList").scrollTop = document.getElementById("MessagesList").scrollHeight;
        }
    });
    $(document).on("click", ".main_messages_convo", function(){
        $(this).find('.main_messages_convo_nM').remove();
        $(".main_messages_convo_active").removeClass("main_messages_convo_active");
        $(this).addClass("main_messages_convo_active");
    });
    $(document).on("click", ".main_messages_im_person_close", function(){
        $(".main_messages").removeClass("opened_im");
        $(".main_messages_im").hide();
        $(".main_messages_convo_active").removeClass("main_messages_convo_active");
    });
});

function getConvos(){
    $.get("/api/Messages/get_conversations", function(data){
        $("#Messages").html("");
        $(data).each(function(i, item){
            $("#Messages").append(`<section class="main_messages_convo" onclick="openMessages('${item.nickname}');">
                <div class="main_messages_convo_image">
                    <img src="${item.profileImage}/w42-compressed.jpeg" alt="Profile picture">
                </div>
                <div class="main_messages_convo_info">
                    <div>
                        <span>
                            <b>${item.name}</b>
                        </span>
                    </div>
                    <div>
                        <span>
                            @${item.nickname}
                        </span>
                    </div>
                </div>
                <div class="main_messages_convo_nM"${item.new_msgs == 0 ? ' style="display:none;"' : ''}><span>${item.new_msgs}</span></div>
            </section>`);
        });
    });
}

function openMessages(nickname){
    $(".main_messages_im").show();
    $.post("/api/User/get_user_by_nickname", {nickname:nickname}, function(data){
        $(".main_messages_im").attr("data-convo", nickname);
        $(".main_messages_im_person_image img").attr("src", data.profileImage);
        $(".main_messages_im_person_info span").html(data.name);
        $(".main_messages_write_message_element").attr("onclick", `sendMessage('${data.nickname}')`);
        $(".main_messages_write_message_element input[type='file']").attr("onchange", `sendImage(this, '${data.nickname}')`);
        $(".main_messages_write_message_textarea textarea").attr("onkeydown", `sendbyenter('${data.nickname}', event)`);
    });
    if(!$(".main_messages").hasClass("opened_im")){
        $(".main_messages").addClass("opened_im");
    }
    getMessages(nickname);
}
function deleteMessage(id, delete_msg = false){
    if(delete_msg == false){
        $("#app").prepend(`<div class="popup popup_choose_alert popup_do_not_close">
            <div>
                <div>
                    <h1>
                        ${locale['do_u_want_to_delete_message']}
                    </h1>
                </div>
                <div style="display: flex;">
                    <div class="button button_gray" onclick="$('.popup:first').remove();" style="margin:0 auto;">
                        ${locale['cancel']}
                    </div>
                    <div class="button" onclick="$('.popup:first').remove();deleteMessage(${id}, true);" style="margin:0 auto;">
                        ${locale['delete']}
                    </div>
                </div>
            </div>
        </div>`);
        return false;
    }
    $.post("/api/Messages/delete_message", {id:id}, function(){
        $(`#msg${id}`).remove();
        conn.send(JSON.stringify({to:$(".main_messages_im").data("convo"), by:user.nickname, deleted:true}));
    });
}
function getMessages(nickname){
    $.post("/api/Messages/get_messages", {to:nickname}, function(data){
        $(".main_messages_im_msgs").html('');
        $(data).each(function(i, item){
            messagecontent = (item.image != '' ? `<img src="${item.image}/w800-compressed.jpeg">` : `<span>${item.message}</span>`);
            if(item.nickname == user.nickname){
                $(".main_messages_im_msgs").append(`<div class="main_messages_im_msg main_messages_im_msg_b_u" id="msg${item.id}">
                    <div class="main_messages_im_msg_btns">
                        <div class="main_messages_im_msg_btn" onclick="deleteMessage(${item.id});">
                            <i class='bx bx-trash' ></i>
                        </div>
                    </div>
                    <div class="main_messages_im_msg_itself">
                        <div>
                            ${messagecontent}
                        </div>
                        <div id="datetimestamp">
                            <small>
                                ${item.added}
                            </small>
                        </div>
                    </div>
                </div>`);
            }else{
                $(".main_messages_im_msgs").append(`<div class="main_messages_im_msg">
                    <div class="main_messages_im_msg_itself">
                        <div>
                            ${messagecontent}
                        </div>
                        <div id="datetimestamp">
                            <small>
                                ${item.added}
                            </small>
                        </div>
                    </div>
                </div>`);
            }
        });
        document.getElementById("MessagesList").scrollTop = document.getElementById("MessagesList").scrollHeight;
    });
}

function sendbyenter (nickname, event) {
    if(event.keyCode === 13 && !event.shiftKey){
        sendMessage(nickname);
        event.preventDefault();
    }
}
function sendMessage(nickname){
    if($(`.main_messages_write_message_textarea textarea`).val().replace(/\s/g,'')){
        if(connection != false){
            $(".main_messages_im_msgs").append(`<div class="main_messages_im_msg main_messages_im_msg_b_u main_messages_im_msg_sending">
                    <div class="main_messages_im_msg_itself">
                        <div>
                            <span>${$(`.main_messages_write_message_textarea textarea`).val().replace(/<[^>]*>?/gm, '')}</span>
                        </div>
                        <div id="datetimestamp">
                            <small>
                                ${locale['sending']}
                            </small>
                        </div>
                    </div>
                </div>`);
            document.getElementById("MessagesList").scrollTop = document.getElementById("MessagesList").scrollHeight;
            $.post("/api/Messages/send_message", {to:nickname, message:$(`.main_messages_write_message_textarea textarea`).val()}, function(data){
                if(data.error != null){
                    zhabbler.addError(`${locale['something_went_wrong']} (details: ${data.error})`);
                }
                getMessages(nickname);
                getConvos();
                $(`.main_messages_write_message_textarea textarea`).val("");
                $.post("/api/Messages/check_is_there_an_unread_msgs", {to:nickname}, function(data){
                    if(data.result == 1){
                        conn.send(JSON.stringify({to:nickname, by:user.nickname}));
                    }
                });
            });
        }else{
            zhabbler.addError(locale['failed_to_connect_msgs']);
        }
    }
}
function sendImage(element, nickname){
    if(connection == false){
        zhabbler.addError(locale['failed_to_connect_msgs']);
        return false;
    }
    const file = element.files[0];
    if(file){
        $(".main_messages_im_msgs").append(`<div class="main_messages_im_msg main_messages_im_msg_b_u main_messages_im_msg_sending">
            <div class="main_messages_im_msg_itself">
                <div>
                    <img src="${URL.createObjectURL(file)}">
                </div>
                <div id="datetimestamp">
                    <small>
                        ${locale['sending']}
                    </small>
                </div>
            </div>
        </div>`);
        document.getElementById("MessagesList").scrollTop = document.getElementById("MessagesList").scrollHeight;
        var formData = new FormData();
        formData.append('image', file);
        formData.append('to', nickname);
        $.ajax({
            url: "/api/Messages/send_image",
            type: "POST",
            data: formData,
            enctype: 'multipart/form-data',
            processData: false,
            contentType: false
        }).done(function(data){
            if(data.error != null){
                zhabbler.addError(`${locale['something_went_wrong']} (details: ${data.error})`);
            }
            getMessages(nickname);
            getConvos();
            $(`.main_messages_write_message_textarea textarea`).val("");
            $.post("/api/Messages/check_is_there_an_unread_msgs", {to:nickname}, function(data){
                if(data.result == 1){
                    conn.send(JSON.stringify({to:nickname, by:user.nickname}));
                }
            });
        }).fail(function(){
            zhabbler.addError(locale['something_went_wrong']);
        });
    }
}

function writeMessage(){
    $("#app").prepend(`<div class="popup"><div class="popup_container" style="padding:0px;height:450px;width:300px;" id="PopupContainer"><div class="loader loader_black loader_cpa"><div class="loader_part loader_part_1"></div><div class="loader_part loader_part_2"></div><div class="loader_part loader_part_3"></div></div></div></div>`);
    $("#app .popup:first .popup_container").load("/etc/messages");
}