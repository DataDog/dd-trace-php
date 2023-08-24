const path = require('path');
const fs = require('fs');
const webpack = require('webpack');
const TerserPlugin = require('terser-webpack-plugin');

function getDirectories(srcpath) {
  return fs
    .readdirSync(srcpath)
    .filter((item) => fs.statSync(path.join(srcpath, item)).isDirectory());
}

const prodPluginBuilds = [];
const devPluginBuilds = [];

// Loop through every subdirectory in ckeditor5_plugins, which should be a different
// plugin, and build them all in ./build.
getDirectories(path.resolve(__dirname, './js/ckeditor5_plugins')).forEach((dir) => {
  const bc = {
    mode: 'production',
    optimization: {
      minimize: true,
      minimizer: [
        new TerserPlugin({
          terserOptions: {
            format: {
              comments: false,
            },
          },
          test: /\.js(\?.*)?$/i,
          extractComments: false,
        }),
      ],
      moduleIds: 'named',
    },
    entry: {
      path: path.resolve(
        __dirname,
        'js/ckeditor5_plugins',
        dir,
        'src/index.js',
      ),
    },
    output: {
      path: path.resolve(__dirname, './js/build'),
      filename: `${dir}.js`,
      library: ['CKEditor5', dir],
      libraryTarget: 'umd',
      libraryExport: 'default',
    },
    plugins: [
      new webpack.BannerPlugin('cspell:disable'),
      new webpack.DllReferencePlugin({
        manifest: require(path.resolve(__dirname, '../../node_modules/ckeditor5/build/ckeditor5-dll.manifest.json')), // eslint-disable-line global-require, import/no-unresolved
        scope: 'ckeditor5/src',
        name: 'CKEditor5.dll',
      }),
    ],
    module: {
      rules: [{ test: /\.svg$/, type: 'asset/source' }],
    },
  };

  const dev = {...bc, mode: 'development', optimization: {...bc.optimization, minimize: false}, devtool: false};

  prodPluginBuilds.push(bc);
  devPluginBuilds.push(dev);
});

module.exports = (env, argv) => {
  // Files aren't minified in build with the development flag.
  if (argv.mode === 'development') {
    return devPluginBuilds;
  } else {
    return prodPluginBuilds;
  }
}
