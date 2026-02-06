const path = require('path');
const Dotenv = require('dotenv-webpack');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const fs = require('fs');

// Check if the bundle entry point exists
const bundleEntryPath = path.join(__dirname, 'resources', 'app.js');
const hasBundleEntry = fs.existsSync(bundleEntryPath);

// Configure entry points
const entry = {
  // Main SCSS file
  styles: path.join(__dirname, 'resources', 'css', 'scss', 'nraa.scss'),
};

// Add bundle entry only if it exists
if (hasBundleEntry) {
  entry.bundle = bundleEntryPath;
}

module.exports = {
  mode: process.env.NODE_ENV === 'production' ? 'production' : 'development',
  
  entry: entry,
  
  watch: process.env.NODE_ENV !== 'production',
  
  output: {
    path: path.join(__dirname, 'public'),
    publicPath: '/',
    filename: 'js/[name].js',
    chunkFilename: 'js/[name].chunk.js',
    clean: false, // Don't clean the entire public folder
  },
  
  module: {
    rules: [
      // JavaScript/JSX files
      {
        test: /\.jsx?$/,
        include: [
          path.resolve(__dirname, 'resources')
        ],
        exclude: [
          path.resolve(__dirname, 'node_modules')
        ],
        loader: 'babel-loader',
        options: {
          presets: [
            ["@babel/env", {
              "targets": {
                "browsers": "last 2 chrome versions"
              }
            }]
          ]
        }
      },
      
      // TypeScript files
      {
        test: /\.tsx?$/,
        include: [
          path.resolve(__dirname, 'resources', 'js')
        ],
        exclude: [
          path.resolve(__dirname, 'node_modules')
        ],
        use: 'ts-loader'
      },
      
      // SCSS/CSS files
      {
        test: /\.s[ac]ss$/i,
        include: [
          path.resolve(__dirname, 'resources', 'css')
        ],
        use: [
          // Extract CSS to separate files
          MiniCssExtractPlugin.loader,
          // Translates CSS into CommonJS
          'css-loader',
          // Compiles Sass to CSS
          'sass-loader',
        ],
      },
      
      // Regular CSS files (if needed)
      {
        test: /\.css$/i,
        exclude: /\.s[ac]ss$/i,
        use: [
          MiniCssExtractPlugin.loader,
          'css-loader',
        ],
      },
    ]
  },
  
  resolve: {
    extensions: ['.tsx', '.ts', '.jsx', '.js', '.json', '.scss', '.css'],
    fallback: {
      "process": require.resolve('process/browser')
    }
  },
  
  plugins: [
    new Dotenv(),
    new MiniCssExtractPlugin({
      filename: 'css/[name].css',
      chunkFilename: 'css/[name].chunk.css',
    }),
  ],
  
  devtool: process.env.NODE_ENV === 'production' ? 'source-map' : 'eval-source-map',
  
  devServer: {
    static: {
      directory: path.join(__dirname, 'public'),
    },
    compress: true,
    host: 'localhost',
    port: 8080,
  },
  
  // Performance hints
  performance: {
    hints: process.env.NODE_ENV === 'production' ? 'warning' : false,
    maxEntrypointSize: 512000,
    maxAssetSize: 512000,
  }
};