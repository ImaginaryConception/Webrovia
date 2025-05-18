document.addEventListener('DOMContentLoaded', function() {
    // Gestionnaire pour les boutons de fichiers
    document.querySelectorAll('.file-btn').forEach(button => {
        button.addEventListener('click', function() {
            const promptId = this.dataset.promptId;
            const filePath = this.dataset.filePath;

            // Retirer la classe active de tous les boutons
            document.querySelectorAll('.file-btn').forEach(btn => btn.classList.remove('active'));
            // Ajouter la classe active au bouton cliqué
            this.classList.add('active');

            // Mettre à jour l'URL de l'iframe avec le chemin du fichier
            const previewFrame = document.querySelector(`.preview-frame[data-prompt-id="${promptId}"]`);
            if (previewFrame) {
                const currentSrc = new URL(previewFrame.src);
                currentSrc.searchParams.set('path', filePath);
                previewFrame.src = currentSrc.toString();
            }

            // Charger le contenu du fichier dans l'éditeur
            fetch(`/api/file-content/${promptId}?path=${encodeURIComponent(filePath)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const editorContainer = document.querySelector(`.editor-container[data-prompt-id="${promptId}"]`);
                    if (editorContainer && window.editors && window.editors[promptId]) {
                        window.editors[promptId].setValue(data.content);
                        window.editors[promptId].refresh();
                    }
                } else {
                    throw new Error(data.message || 'Erreur lors du chargement du fichier');
                }
            })
            .catch(error => {
                const errorAlert = document.getElementById('error-alert');
                const errorMessage = document.getElementById('error-message');
                if (errorAlert && errorMessage) {
                    errorMessage.textContent = error.message;
                    errorAlert.classList.remove('hidden');
                }
            });
        });
    });

    // Gestionnaire pour fermer l'alerte d'erreur
    const closeError = document.getElementById('close-error');
    if (closeError) {
        closeError.addEventListener('click', function() {
            document.getElementById('error-alert').classList.add('hidden');
        });
    }
});