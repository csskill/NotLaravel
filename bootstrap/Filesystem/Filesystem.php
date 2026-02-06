<?php

namespace Nraa\Filesystem;

use \League\Flysystem\Ftp\FtpAdapter;
use \League\Flysystem\Ftp\FtpConnectionOptions;
use \League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use \League\Flysystem\PhpseclibV3\SftpAdapter;
use \League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use \League\Flysystem\Filesystem as Flysystem;
use \League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use Google\Cloud\Storage\StorageClient;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class Filesystem
{

    protected array $config;
    protected array $environment;
    protected string $storagePath;
    public array $filesystem = [];

    /**
     * Summary of __construct
     * @param string $storagePath
     * @param array $environment
     * @param array $config
     */
    public function __construct(string $storagePath, array $config = [])
    {
        $this->storagePath = $storagePath;
        $this->config = $config;
        $this->initialize();
    }

    /**
     * Initializes the filesystem.
     * @return void
     */
    public function initialize()
    {
        foreach ($this->config as $key => $value) {
            switch ($value['driver']) {
                case 's3':
                    $this->initiateS3Adapter($key, $this->config[$key]);
                    break;
                case 'local':
                    $this->intiateLocalStorage($key, $this->config[$key]);
                    break;
                case 'ftp':
                    $this->initiateFTPStorage($key, $this->config[$key]);
                    break;
                case 'sftp':
                    $this->initiateSFTPStorage($key, $this->config[$key]);
                    break;
                case 'google':
                    $this->initiateGoogleStorage($key, $this->config[$key]);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Initiates the local storage adapter.
     * @param string $key The filesystem key.
     * @param array $config The configuration for the local storage adapter.
     * @return void
     */
    public function intiateLocalStorage(string $key, array $config)
    {
        // The internal adapter
        $adapter = new LocalFilesystemAdapter(
            // Will be httpdocs/storage/$config['root'], e.g. httpdocs/storage/files
            $this->storagePath . DIRECTORY_SEPARATOR . $config['root']
        );

        // The FilesystemOperator
        $this->filesystem[$key] = new Flysystem($adapter);
    }

    /**
     * Initiates the S3 adapter.
     * @param string $key The filesystem key.
     * @param array $config The configuration for the S3 adapter.
     * @return void
     */
    public function initiateS3Adapter(string $key, array $config)
    {
        /** @var \Aws\S3\S3ClientInterface $client */
        $client = new S3Client($config);

        // The internal adapter
        $adapter = new AwsS3V3Adapter(
            // S3Client
            $client,
            // Bucket name
            $config['bucket']
        );

        $this->filesystem[$key] = new Flysystem($adapter);
    }

    /**
     * Initiates the FTP storage adapter.
     * @param string $key The filesystem key.
     * @param array $config The configuration for the FTP adapter.
     * @return void
     */
    public function initiateFTPStorage(string $key, array $config)
    {
        // The internal adapter
        $adapter = new FtpAdapter(
            // Connection options
            FtpConnectionOptions::fromArray($config)
        );
        $this->filesystem[$key] = new Flysystem($adapter);
    }

    /**
     * Initiates the SFTP storage adapter.
     * @param string $key The filesystem key.
     * @param array $config The configuration for the SFTP adapter.
     * @return void
     */
    public function initiateSFTPStorage(string $key, array $config)
    {
        $filesystem = new Flysystem(new SftpAdapter(
            new SftpConnectionProvider(
                $config['host'], // host (required)
                $config['username'], // username (required)
                $config['password'] ?? null, // password (optional, default: null) set to null if privateKey is used
                $config['privateKey'] ?? null, // private
                $config['passphrase'] ?? null, // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
                $config['port'] ?? 22, // port (optional, default: 22)
                $config['useAgent'] ?? false, // use agent (optional, default: false)
                $config['timeout'] ?? 10, // timeout (optional, default: 10)
                $config['maxTries'] ?? 4, // max tries (optional, default: 4)
                $config['hostFingerprint'] ?? null, // host fingerprint (optional, default: null),
                $config['connectivityChecker'] ?? null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
            ),
            $config['root'] ?? '/', // root path (required)
            PortableVisibilityConverter::fromArray(
                $config['permissions'] ?? [] // permissions (optional, default: [])
            )
        ));
        $this->filesystem[$key] = $filesystem;
    }

    /**
     * Initiates the Google Cloud Storage adapter.
     * @param string $key The filesystem key.
     * @param array $config The configuration for the Google Cloud Storage adapter.
     * @return void
     */
    public function initiateGoogleStorage(string $key, array $config)
    {
        $storageClient = new StorageClient($config);
        $bucket = $storageClient->bucket($config['bucket']);

        $adapter = new GoogleCloudStorageAdapter($bucket, $config['prefix'] ?? '');
        $this->filesystem[$key] = new Flysystem($adapter);
    }
}
