/**
 * Script pour gérer le déclenchement de la traduction via AJAX
 * depuis la meta box.
 */
(function($) { // Encapsulation pour éviter les conflits, $ est une alias de jQuery
    'use strict';

    $(function() { // Équivalent de $(document).ready()

        const $button = $('#gpt-translate-button');
        const $messageArea = $('#gpt-translate-message-area');
        const $nonceField = $('#gpt_translate_nonce'); // Champ nonce caché

        if (!$button.length || !$messageArea.length || !$nonceField.length) {
            // Si un des éléments manque, ne rien faire
            return;
        }

        $button.on('click', function(e) {
            e.preventDefault(); // Empêcher le comportement par défaut si c'était un lien/submit

            const postId = $(this).data('postid');
            const nonce = $nonceField.val();

            if (!postId || !nonce) {
                $messageArea.html('<p style="color:red;">Error: Missing Post ID or Nonce.</p>');
                return;
            }

            // --- Affichage "En cours" ---
            $messageArea.html('<p><em>' + gptTranslateData.translating_text + '</em> <span class="spinner is-active" style="float:none; vertical-align: middle;"></span></p>');
            $button.prop('disabled', true); // Désactiver le bouton

            // --- Préparation des données AJAX ---
            const ajaxData = {
                action: 'gpt_auto_translate_request', // Nom de notre action AJAX côté PHP
                post_id: postId,
                nonce: nonce
            };

            // --- Appel AJAX ---
            $.post(ajaxurl, ajaxData, function(response) {
                // 'ajaxurl' est une variable globale définie par WordPress dans l'admin

                // Réactiver le bouton dans tous les cas (succès ou erreur)
                $button.prop('disabled', false);

                if (response.success) {
                    // Succès global de la requête AJAX
                    let resultHtml = '<p style="color:green;">' + gptTranslateData.success_text + '</p>';
                    if (response.data && typeof response.data === 'object') {
                        resultHtml += '<ul style="margin-left: 20px;">';
                        for (const langCode in response.data) {
                            if (response.data.hasOwnProperty(langCode)) {
                                const langResult = response.data[langCode];
                                const statusColor = langResult.success ? 'green' : 'red';
                                resultHtml += `<li><strong>${langCode.toUpperCase()}:</strong> <span style="color:${statusColor};">${langResult.message || 'Unknown status'}</span></li>`;
                            }
                        }
                        resultHtml += '</ul>';
                    }
                     // Optionnel: Ajouter une note pour rafraîchir la page pour voir les liens "Edit" mis à jour
                     resultHtml += '<p><small>' + gptTranslateData.refresh_notice + '</small></p>';

                    $messageArea.html(resultHtml);


                    // Pour l'instant, on ne met pas à jour dynamiquement la liste <ul> principale.
                    // L'utilisateur devra recharger la page pour voir les statuts et liens "Edit" mis à jour.

                } else {
                    // Erreur retournée par wp_send_json_error ou erreur AJAX générique
                    let errorMessage = gptTranslateData.error_text; // Message générique
                    if (response.data && typeof response.data === 'string') {
                         errorMessage += '<br><small>' + response.data + '</small>'; // Afficher le message d'erreur spécifique s'il existe
                    } else if (response.data && response.data.error) {
                        errorMessage += '<br><small>' + response.data.error + '</small>';
                    }
                     $messageArea.html('<p style="color:red;">' + errorMessage + '</p>');
                }

            }).fail(function(xhr, status, error) {
                // Gérer les erreurs de la requête AJAX elle-même (ex: erreur serveur 500)
                 $button.prop('disabled', false);
                 let errorText = gptTranslateData.error_text + ' (AJAX Error)';
                 if(error) {
                     errorText += ': ' + error;
                 }
                 $messageArea.html('<p style="color:red;">' + errorText + '</p>');
                 console.error('GPT Translate AJAX Error:', status, error, xhr.responseText);
            });

        }); // Fin $button.on('click')

    }); // Fin $(document).ready()

})(jQuery); // Fin de l'encapsulation