<?php

namespace App\Traits;

use App\Support\PublicDiskUrl;
use App\Models\Photo;
use App\Services\StoredFileDeletionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Facades\Image;

trait HasPhoto
{
    /**
     * Boots the trait for the eloquent models.
     */
    public static function bootHasPhoto()
    {
        // Delete Old Photos.
        static::deleting(function ($model) {
            // Archiving a course must remain reversible. Its cover is part of
            // the authoring draft and is only retired with a force delete.
            if (
                $model instanceof \App\Models\Course
                && method_exists($model, 'isForceDeleting')
                && !$model->isForceDeleting()
            ) {
                return;
            }
            $model->deleteImages();
        });
    }

    /**
     * Gets the model photos.
     *
     * @return mixed
     */
    public function photos()
    {
        return $this->morphMany(Photo::class, 'photoable')->where('type', 'gallery');
    }

    /** All owned images without the historical gallery-only scope. */
    public function allPhotos()
    {
        return $this->morphMany(Photo::class, 'photoable');
    }

    /**
     * Gets the model photos.
     *
     * @return mixed
     */
    public function galleyPhoto()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'gallery');
    }
    /**
     * @return mixed
     */
    public function photo()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'featured');
    }

    /**
     * @return mixed
     */
    public function identity()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'identity');
    }

    /**
     * @return mixed
     */
    public function family()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'family');
    }

    /**
     * @return mixed
     */
    public function licence()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'licence');
    }

    /**
     * @return mixed
     */
    public function personal()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'personal');
    }  
    
    /**
     * @return mixed
     */
    public function car_licence()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_licence');
    }   
    /**
     * @return mixed
     */
    public function car_photo()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_photo');
    }   
    /**
     * @return mixed
     */
    public function car_photo2()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'car_photo2');
    }                
    /**
     * @return mixed
     */
    public function certification()
    {
        return $this->morphOne(Photo::class, 'photoable')->where('type', 'certification');
    }

    /**
     * Adds an image or multiple images to the model.
     *
     * @param $file
     * @param $path
     * @param string $type
     * @return static
     */
    public function storeImage($file, $path, $type = 'featured', ?string $operationIdentity = null)
    {
        if ($operationIdentity !== null) {
            $logicalPath = app(StoredFileDeletionService::class)->trackedUploadDestination(
                $file, $path, 'public', $operationIdentity
            );
            // A create receipt may fail after its Photo already committed.
            // Only that domain-owned image is replayable, never orphan bytes.
            $existing = $this->allPhotos()->where('type', $type)->get()->first(
                fn (Photo $photo): bool => str_starts_with((string) $photo->path, trim($path, '/') . '/')
                    && basename((string) $photo->path) === basename($logicalPath)
            );
            if ($existing) return $existing;
        }
        /*$image = Image::make($file);
        $image->fit(1900, 750, function ($constraint) {
            $constraint->aspectRatio();
        });
        Storage::disk('public')->put($path, (string) $image->encode());*/
        //Storage::disk('public')->put($path, Image::make($file)->encode('jpg', 50));
        $name = $this->storeTrackedImageBytes($file, $path, $operationIdentity);
        if (!is_string($name) || trim($name) === '') {
            throw new \RuntimeException('Image storage failed');
        }

        try {
            return DB::transaction(function () use ($name, $type) {
                $owner = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                return $owner->allPhotos()->firstOrCreate(['path' => $name, 'type' => $type]);
            }, 3);
        } catch (\Throwable $exception) {
            app(StoredFileDeletionService::class)->deleteOrQueue('public', $name);
            throw $exception;
        }
    }

    /**
     * Replaces the current image with a new one.
     * @param $file
     * @param $path
     * @param string $type
     */
    public function replaceImage($file, $path, $type = 'featured', ?string $operationIdentity = null)
    {
        $name = $this->storeTrackedImageBytes($file, $path, $operationIdentity);
        try {
            return DB::transaction(function () use ($name, $type) {
                $owner = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $newPhoto = $owner->allPhotos()->firstOrCreate(['path' => $name, 'type' => $type]);
                $owner->allPhotos()->where('type', $type)
                    ->where('photos.id', '!=', $newPhoto->getKey())->get()->each->delete();
                return $newPhoto;
            }, 3);
        } catch (\Throwable $exception) {
            app(StoredFileDeletionService::class)->deleteOrQueue('public', $name);
            throw $exception;
        }
    }

    private function storeTrackedImageBytes($file, string $directory, ?string $operationIdentity): string
    {
        return app(StoredFileDeletionService::class)->storeTrackedUpload(
            $file,
            $directory,
            'public',
            60,
            $operationIdentity
        );
    }


    public function deleteImage()
    {
        $this->photo()->delete();
    }

    public function deleteIdentityImage()
    {
        $this->identity()->delete();
    }

    public function deleteFamilyImage()
    {
        $this->family()->delete();
    }

    public function deleteLicenceImage()
    {
        $this->licence()->delete();
    }
    public function deleteCertificationImage()
    {
        $this->certification()->delete();
    }
    public function deleteCheck()
    {
        if ($this->photo) {
            $this->deleteImage();
        }
        if ($this->identity) {
            $this->deleteIdentityImage();
        }
        if ($this->family) {
            $this->deleteFamilyImage();
        }
        if ($this->licence) {
            $this->deleteLicenceImage();
        }
        if ($this->certification) {
            $this->deleteCertificationImage();
        }
    }

    /**
     * @return mixed
     */
    public function getThumbnailAttribute()
    {
        return $this->photo->getThumbnail();
    }

    /**
     * Deletes all images.
     */
    public function deleteImages()
    {
        $this->allPhotos()->get()->each(function ($photo) {
            $photo->delete();
        });
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getImagesAttribute()
    {
        $photos = $this->photos;
        if (! $photos || ! $photos->count()) {
            return collect([]);
        }

        return $photos->map(function ($photo) {
            return $photo;
        });
    }

    /**
     * @return null|string
     */
    public function getImageAttribute()
    {
        $photo = $this->photo;
        if (! $photo) {
            $rawImage = $this->attributes['image'] ?? $this->attributes['profile_image'] ?? null;
            if (!$rawImage) {
                return null;
            }
            if (filter_var($rawImage, FILTER_VALIDATE_URL)) {
                return $rawImage;
            }

            if (str_starts_with(ltrim((string) $rawImage, '/'), 'storage/')) {
                return PublicDiskUrl::from((string) $rawImage);
            }

            return asset(ltrim($rawImage, '/'));
        }

        return PublicDiskUrl::from($photo->path);
    }
    /**
     * @return null|string
     */
    public function getGalleryAttribute()
    {
        $galleyPhoto = $this->galleyPhoto;
        if (! $galleyPhoto) {
            return null;
        }

        return PublicDiskUrl::from($galleyPhoto->path);
    }    

    /**
     * @return null|string
     */
    public function getIdentityImageAttribute()
    {
        $photo = $this->identity;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }

    /**
     * @return null|string
     */
    public function getFamilyImageAttribute()
    {
        $photo = $this->family;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }

    /**
     * @return null|string
     */
    public function getLicenceImageAttribute()
    {
        $photo = $this->licence;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }

    /**
     * @return null|string
     */
    public function getPersonalImageAttribute()
    {
        $photo = $this->personal;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    } 
    /**
     * @return null|string
     */
    public function getCarLicenceImageAttribute()
    {
        $photo = $this->car_licence;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }  
    /**
     * @return null|string
     */
    public function getCarPhotoImageAttribute()
    {
        $photo = $this->car_photo;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    } 
    /**
     * @return null|string
     */
    public function getCarPhotoImage2Attribute()
    {
        $photo = $this->car_photo2;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }               
    /**
     * @return null|string
     */
    public function getCertificationImageAttribute()
    {
        $photo = $this->certification;
        if (! $photo) {
            return null;
        }

        return PublicDiskUrl::from($photo->path);
    }
    /**
     * @return null|string
     */
    public function getPathAttribute()
    {
        $photo = $this->photo;
        if (! $photo) {
            return null;
        }

        return (public_path().'/storage/' .$photo->path);
    }
}
