const fs = require('fs');
const { exec } = require('child_process');
// const { rm } = require('./utils/file-actions');

const cwd = process.cwd();

const rm = (path) => {
	if (fs.existsSync(path)) {
		exec(`rm -r ${path}`, (err) => {
			if (err) {
				console.log(err);
			}
		});
	}
};

const clean = (dir) => {
	rm(`${dir}/node_modules`);
	rm(`${dir}/build`);
	rm(`${dir}/dist`);
	rm(`${dir}/vendor`);
	rm(`${dir}/pnpm-lock.yaml`);
};

const cleanRoot = () => clean(cwd);

const cleanWorkSpaces = () => {
	const workspaces = ['./packages'];

	workspaces.forEach((workspace) => {
		fs.readdir(workspace, (err, folders) => {
			folders.forEach((folder) => {
				clean(`${cwd}/${workspace}/${folder}`);
			});

			if (err) {
				throw err;
			}
		});
	});
};

cleanRoot();
cleanWorkSpaces();
