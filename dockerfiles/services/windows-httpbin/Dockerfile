FROM chocolatey/choco:latest-windows

RUN choco.exe install -y python310

ADD https://github.com/psf/httpbin/releases/download/release-0.10.1/requirements.txt /requirements.txt
RUN python3.10.exe -m pip install --no-deps --requirement /requirements.txt

RUN python3.10.exe -m pip install waitress

ADD https://github.com/psf/httpbin/archive/refs/tags/release-0.10.1.zip /httpbin.zip
RUN powershell.exe Expand-Archive -Force /httpbin.zip

WORKDIR /httpbin/httpbin-release-0.10.1

RUN python3.10.exe -m pip install --no-deps .

EXPOSE 80
CMD [ "waitress-serve.exe", "--listen=0.0.0.0:80", "httpbin:app" ]
