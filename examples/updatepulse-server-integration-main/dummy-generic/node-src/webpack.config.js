module.exports = {
  entry: './exports.js',
  output: {
    filename: '../node-dist/exports.js',
    path: __dirname,
    library: 'updatepulseAPIModules',
    libraryTarget: 'umd',
  },
  target: 'node',
  mode: 'production',
};