FROM mcr.microsoft.com/windows/servercore:1809

ADD https://www.python.org/ftp/python/3.10.11/python-3.10.11-amd64.exe /python-3.10.11.exe
RUN /python-3.10.11.exe /quiet InstallAllUsers=1 PrependPath=1 Include_test=0

ADD https://github.com/psf/httpbin/releases/download/release-v0.10.1/requirements.txt /requirements.txt
RUN python.exe -m pip install --no-deps --requirement /requirements.txt

RUN python.exe -m pip install waitress

ADD https://github.com/psf/httpbin/archive/refs/tags/release-v0.10.1.zip /httpbin.zip
RUN powershell.exe Expand-Archive -Force /httpbin.zip

WORKDIR /httpbin/httpbin-release-v0.10.1

RUN python.exe -m pip install --no-deps .

EXPOSE 80
CMD [ "waitress-serve.exe", "--listen=0.0.0.0:80", "httpbin:app" ]
