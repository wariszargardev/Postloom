import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MediaController from '@/actions/App/Http/Controllers/MediaController';

/**
 * Upload files to the media library without leaving the current page:
 * only the editor's `library` prop is reloaded, so an unsaved post keeps its state.
 */
export function useMediaUpload() {
    const form = useForm<{ files: File[] }>({ files: [] });

    const errors = computed(() =>
        Object.entries(form.errors)
            .filter(([key]) => key === 'files' || key.startsWith('files.'))
            .map(([, message]) => message),
    );

    function upload(
        files: FileList | File[] | null,
        onUploaded: (mediaIds: number[]) => void,
    ): void {
        if (!files || files.length === 0) {
            return;
        }

        form.files = Array.from(files);
        form.post(MediaController.store().url, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['library'],
            onFlash: (flash) => {
                const ids = (flash as Record<string, unknown>)
                    .uploaded_media_ids;

                if (Array.isArray(ids)) {
                    onUploaded(ids.map(Number));
                }
            },
            onFinish: () => {
                form.files = [];
            },
        });
    }

    return { form, errors, upload };
}
